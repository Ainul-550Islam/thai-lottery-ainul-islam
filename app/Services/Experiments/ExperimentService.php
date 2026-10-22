<?php

declare(strict_types=1);

namespace App\Services\Experiments;

use App\Models\ExperimentExposure;
use App\Models\User;
use App\Support\ResponsibleGamingGate;
use Illuminate\Support\Carbon;

/**
 * Deterministic Feature-Flag A/B Experimentation Engine (Audit §D6.7).
 *
 * DESIGN PROPERTIES
 * -----------------
 * 1. ZERO FLICKER & ZERO DB QUERIES FOR ASSIGNMENT: bucketing hashes
 *    `"{experiment}:{salt}:{subject}"` with SHA-256 into uniform basis points
 *    `[0 .. 9999]` on the server before HTML renders.
 * 2. STICKY ACROSS REQUESTS: the same member ID (or session anonymous token)
 *    always receives the exact same variant without external SaaS calls.
 * 3. RESPONSIBLE GAMBLING OVERRIDE: any experiment flagged `'promotional' => true`
 *    unconditionally assigns `'control'` to self-excluded players.
 * 4. ANALYTICS READY: `activeAssignments()` produces a clean map of assigned
 *    variants ready to push into D3's dataLayer without PII.
 */
final class ExperimentService
{
    public const CONTROL = 'control';

    /**
     * Resolve the assigned variant for an experiment.
     */
    public function variant(
        string $experimentKey,
        User|int|string|null $subject,
        bool $recordExposure = false,
    ): string {
        if (! (bool) config('conversion_tooling.experiments.enabled', true)) {
            return self::CONTROL;
        }

        /** @var array<string, mixed>|null $definition */
        $definition = config("conversion_tooling.experiments.definitions.{$experimentKey}");
        if (! is_array($definition) || ! (bool) ($definition['enabled'] ?? false)) {
            return self::CONTROL;
        }

        if ((bool) ($definition['promotional'] ?? false)
            && $subject instanceof User
            && ResponsibleGamingGate::isSelfExcluded($subject)
        ) {
            return self::CONTROL;
        }

        $subjectIdentifier = $this->resolveSubjectIdentifier($subject);
        if ($subjectIdentifier === null) {
            return self::CONTROL;
        }

        $trafficPercent = max(0, min(100, (int) ($definition['traffic_percent'] ?? 100)));
        if ($trafficPercent <= 0) {
            return self::CONTROL;
        }

        // Separate hash stream for traffic holdout vs variant split so changing
        // traffic_percent from 50% to 100% preserves uniform 50/50 split ratios!
        if ($trafficPercent < 100) {
            $trafficBucket = $this->uniformBucket($experimentKey.':traffic', $subjectIdentifier);
            if ($trafficBucket >= ($trafficPercent * 100)) {
                return self::CONTROL;
            }
        }

        /** @var array<string, int|float> $variants */
        $variants = (array) ($definition['variants'] ?? []);
        $assigned = $this->pickVariant($experimentKey, $subjectIdentifier, $variants);

        if ($recordExposure || (bool) config('conversion_tooling.experiments.record_exposures', false)) {
            $this->logExposure($experimentKey, $subject, $subjectIdentifier, $assigned);
        }

        return $assigned;
    }

    public function is(
        string $experimentKey,
        string $expectedVariant,
        User|int|string|null $subject,
    ): bool {
        return $this->variant($experimentKey, $subject) === $expectedVariant;
    }

    /**
     * Return `{experiment_key => variant}` for all currently active experiments.
     *
     * @return array<string, string>
     */
    public function activeAssignments(User|int|string|null $subject): array
    {
        if (! (bool) config('conversion_tooling.experiments.enabled', true)) {
            return [];
        }

        /** @var array<string, mixed> $definitions */
        $definitions = (array) config('conversion_tooling.experiments.definitions', []);
        $assignments = [];

        foreach ($definitions as $key => $def) {
            if (! is_array($def) || ! (bool) ($def['enabled'] ?? false)) {
                continue;
            }

            $assignments[(string) $key] = $this->variant((string) $key, $subject);
        }

        return $assignments;
    }

    /**
     * Deterministic uniform integer in `[0 .. 9999]`.
     */
    public function uniformBucket(string $namespace, string $subjectIdentifier): int
    {
        $salt = (string) config('conversion_tooling.experiments.salt', 'tl-exp-v1');
        $digest = hash('sha256', $namespace.':'.$salt.':'.$subjectIdentifier, true);
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($digest, 0, 4));

        return $unpacked[1] % 10000;
    }

    /**
     * @param  array<string, int|float>  $variants
     */
    private function pickVariant(string $experimentKey, string $subjectIdentifier, array $variants): string
    {
        $positive = [];
        $totalWeight = 0.0;

        foreach ($variants as $name => $weight) {
            $w = (float) $weight;
            if ($w > 0.0) {
                $positive[(string) $name] = $w;
                $totalWeight += $w;
            }
        }

        if ($positive === [] || $totalWeight <= 0.0) {
            return self::CONTROL;
        }

        $bucket = $this->uniformBucket($experimentKey.':split', $subjectIdentifier);
        $cursor = 0.0;
        $lastKey = self::CONTROL;

        foreach ($positive as $name => $weight) {
            $lastKey = $name;
            $cursor += ($weight / $totalWeight) * 10000.0;
            if ((float) $bucket < $cursor) {
                return $name;
            }
        }

        return $lastKey;
    }

    private function resolveSubjectIdentifier(User|int|string|null $subject): ?string
    {
        if ($subject instanceof User) {
            $id = (int) $subject->getKey();

            return $id > 0 ? 'user:'.$id : null;
        }

        if (is_int($subject)) {
            return $subject > 0 ? 'user:'.$subject : null;
        }

        if (is_string($subject)) {
            $trimmed = trim($subject);

            return $trimmed !== '' ? 'anon:'.$trimmed : null;
        }

        return null;
    }

    private function logExposure(
        string $experimentKey,
        User|int|string|null $subject,
        string $subjectIdentifier,
        string $variant,
    ): void {
        $salt = (string) config('conversion_tooling.experiments.salt', 'tl-exp-v1');
        $subjectHash = hash_hmac('sha256', $subjectIdentifier, $salt);

        $exists = ExperimentExposure::query()
            ->where('experiment_key', $experimentKey)
            ->where('subject_hash', $subjectHash)
            ->exists();

        if ($exists) {
            return;
        }

        ExperimentExposure::query()->create([
            'experiment_key' => $experimentKey,
            'subject_hash' => $subjectHash,
            'user_id' => $subject instanceof User ? (int) $subject->getKey() : (is_int($subject) ? $subject : null),
            'variant' => $variant,
            'exposed_at' => Carbon::now(),
        ]);
    }
}
