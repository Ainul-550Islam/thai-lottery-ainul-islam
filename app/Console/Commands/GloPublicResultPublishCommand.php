<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Enums\DrawStatus;
use App\Enums\GloSourceState;
use App\Enums\RiskLevel;
use App\Exceptions\DrawResultException;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\DrawReconciliation;
use App\Models\DrawResult;
use App\Services\Draw\DrawResultPublicationService;
use App\Services\PublicPages\PublishedResultSourceResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * glo:publish-public-result — promote a VERIFIED result version into the
 * public projection cache. Never invents numbers; refuses unverified draws,
 * refuses to overwrite a different finalized public version without a new
 * result_version fingerprint (corrections create a new version + audit).
 */
class GloPublicResultPublishCommand extends Command
{
    protected $signature = 'glo:publish-public-result
        {--draw= : Local draw id or draw_number}
        {--force : Republish the SAME result_version fingerprint only}
        {--actor= : Operator email for audit attribution}
        {--json : JSON output}
        {--allow-fixture-in-production : Explicitly accept fixture-sourced publication in production}';

    protected $description = 'Publish only verified GLO result versions into the public projection (never fabricates)';

    public function handle(): int
    {
        // Gate 0 (audit S3): the official-source mode defaults to fixture
        // for local/test boots. Production must set GLO_OFFICIAL_SOURCE_MODE
        // explicitly before this command is trusted with the public result
        // lane; publishing fixture data there requires the explicit
        // acknowledgement flag, in the command output and in the audit log.
        if (config('app.env') === 'production'
            && (string) config('glo.official_source.mode', 'fixture') === 'fixture'
            && ! $this->option('allow-fixture-in-production')) {
            $this->error(
                'GLO_OFFICIAL_SOURCE_MODE is "fixture" — refusing to publish fixture-sourced results in production. '
                .'Configure the intended official source mode, or pass --allow-fixture-in-production to accept fixture data explicitly.'
            );

            return self::FAILURE;
        }

        $asJson = (bool) $this->option('json');
        $drawRef = (string) $this->option('draw');

        if ($drawRef === '') {
            $this->error('--draw= is required.');

            return self::FAILURE;
        }

        $draw = ctype_digit($drawRef)
            ? Draw::query()->find((int) $drawRef)
            : Draw::query()->where('draw_number', $drawRef)->first();

        if ($draw === null) {
            $this->error('Draw not found: '.$drawRef);

            return self::FAILURE;
        }

        // Gate 1: draw must be finalized for public reading.
        if (! in_array($draw->status, [DrawStatus::ResultPublished, DrawStatus::Completed], true)) {
            $this->error(sprintf('Draw %s is not finalized for publication (status=%s).', $draw->draw_number, $draw->status->value));

            return self::FAILURE;
        }

        $result = DrawResult::query()->where('draw_id', $draw->getKey())->first();

        if ($result === null) {
            $this->error('No draw_result row — nothing verified to publish.');

            return self::FAILURE;
        }

        // Gate 2: provenance must exist (import fingerprint or explicit publish hash).
        $tierKey = (string) config('glo.tiers.metadata_key', 'glo');
        $meta = is_array($result->metadata) ? $result->metadata : [];
        $lane = is_array($meta[$tierKey] ?? null) ? $meta[$tierKey] : [];
        $fingerprint = (string) ($lane['import_fingerprint'] ?? $lane['n3_fingerprint'] ?? '');

        if ($fingerprint === '') {
            $this->error('Result has no source fingerprint — refusing to publish unverified data.');

            return self::FAILURE;
        }

        // Gate 3: no unresolved reconciliation conflict on this draw.
        $conflict = DrawReconciliation::query()
            ->where('draw_id', $draw->getKey())
            ->whereIn('status', ['drift_detected', 'pending'])
            ->exists();

        if ($conflict) {
            $this->error('Unresolved reconciliation conflict — publication blocked.');

            return self::FAILURE;
        }

        $provider = strtolower(trim((string) ($lane['import_provider'] ?? '')));
        $provenanceStr = strtolower(trim((string) ($meta['provenance'] ?? '')));
        $isProduction = config('app.env') === 'production' || app()->environment('production');

        if ($isProduction && (in_array($provider, ['fixture', 'fixture_only'], true) || str_contains($provenanceStr, 'fixture'))) {
            $this->error('Result is fixture-sourced — refusing to publish fixture data in production.');

            return self::FAILURE;
        }

        try {
            app(DrawResultPublicationService::class)->verifiedProvenanceFor($draw, $result);
        } catch (DrawResultException $e) {
            $this->error('Result provenance verification failed — refusing to publish unverified data: '.$e->getMessage());

            return self::FAILURE;
        }

        $sourceState = app(PublishedResultSourceResolver::class)->resolve($draw, $result);

        if ($sourceState === GloSourceState::Unavailable) {
            $this->error('Result source state is UNAVAILABLE — refusing to publish unverified data.');

            return self::FAILURE;
        }

        $cacheKey = sprintf(
            'glo:public-result:%s:%s:%s',
            $draw->getKey(),
            $fingerprint,
            strtolower($sourceState->value),
        );

        $previous = Cache::get($cacheKey);
        $sameVersionAlreadyPublished = $previous !== null;
        $forced = (bool) $this->option('force');
        $ttlSeconds = max(1, (int) config('glo.result_experience.cache_ttl_seconds', 60));

        $payload = [
            'draw_id' => (int) $draw->getKey(),
            'draw_number' => $draw->draw_number,
            'first_prize' => $result->first_prize,
            'second_prize' => $result->second_prize,
            'third_prize' => $result->third_prize,
            'consolation_prizes' => $result->consolation_prizes,
            'source_state' => $sourceState->value,
            'result_version' => $fingerprint,
            'published_at' => $result->published_at?->toIso8601String(),
            'fixture_sample' => $sourceState === GloSourceState::FixtureOnly,
        ];

        if (! $sameVersionAlreadyPublished || $forced) {
            Cache::put($cacheKey, $payload, $ttlSeconds);
            Cache::put('glo:public-result:current:'.$draw->getKey(), $cacheKey, $ttlSeconds);

            foreach (['home.current-result', 'home.prize-summary', 'home.stats', 'home.page'] as $homeKey) {
                Cache::forget($homeKey);
            }

            $actor = (string) ($this->option('actor') ?? 'CLI:glo:publish-public-result');
            AuditLog::create([
                'user_id' => null,
                'action' => AuditAction::Update,
                'risk_level' => RiskLevel::Medium,
                'auditable_type' => DrawResult::class,
                'auditable_id' => (int) $result->getKey(),
                'description' => 'glo_public_result_published',
                'metadata' => [
                    'action_type' => 'glo_public_result_published',
                    'draw_id' => (int) $draw->getKey(),
                    'result_version' => $fingerprint,
                    'source_state' => $sourceState->value,
                    'actor' => $actor,
                    'forced' => $forced,
                    'ttl_seconds' => $ttlSeconds,
                ],
            ]);
        }

        $out = [
            'draw_id' => (int) $draw->getKey(),
            'draw_number' => $draw->draw_number,
            'result_version' => $fingerprint,
            'source_state' => $sourceState->value,
            'cache_key' => $cacheKey,
            'ttl_seconds' => $ttlSeconds,
            'already_published' => $sameVersionAlreadyPublished,
            'forced' => $forced,
        ];

        if ($asJson) {
            $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Published %s version %s (%s).',
                $draw->draw_number,
                $fingerprint,
                $sourceState->value,
            ));
        }

        return self::SUCCESS;
    }
}
