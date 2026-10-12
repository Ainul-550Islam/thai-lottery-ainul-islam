<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Enums\DrawStatus;
use App\Enums\GloSourceState;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\DrawReconciliation;
use App\Models\DrawResult;
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
        {--json : JSON output}';

    protected $description = 'Publish only verified GLO result versions into the public projection (never fabricates)';

    public function handle(): int
    {
        // Gate 0: production must be configured for the official source lane.
        // This is a hard refusal; no CLI acknowledgement can authorize fixture
        // data to enter the production public-result projection.
        if (config('app.env') === 'production'
            && (string) config('glo.official_source.mode', 'fixture') === 'fixture') {
            $this->error(
                'GLO_OFFICIAL_SOURCE_MODE is "fixture" — refusing to publish fixture-sourced results in production. '
                .'Configure and verify the official source mode; fixture data cannot be authorized for production publication.'
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

        // Gate 2: trust only the provenance stamp created by the authoritative
        // DrawResultPublicationService. An arbitrary non-empty provider label is
        // not evidence that the row was verified, and missing provenance is not
        // promoted to official by configuration or by this cache publisher.
        $meta = is_array($result->metadata) ? $result->metadata : [];
        $lane = is_array($meta['glo'] ?? null) ? $meta['glo'] : [];
        $provenance = is_string($meta['provenance'] ?? null) ? trim($meta['provenance']) : '';
        $provenanceReference = is_string($meta['provenance_reference'] ?? null)
            ? trim($meta['provenance_reference'])
            : '';
        $provider = is_string($lane['import_provider'] ?? null)
            ? strtolower(trim($lane['import_provider']))
            : '';
        $fingerprint = is_string($lane['import_fingerprint'] ?? null)
            ? strtolower(trim($lane['import_fingerprint']))
            : '';

        if (preg_match('/\\A[a-f0-9]{64}\\z/iD', $fingerprint) !== 1) {
            $this->error('Result has no valid SHA-256 provider fingerprint — refusing to publish unverified data.');

            return self::FAILURE;
        }

        if ($provenance === 'fixture') {
            if ($provider !== 'fixture' || $provenanceReference === '') {
                $this->error('Fixture provenance does not match the persisted GLO source lane — publication refused.');

                return self::FAILURE;
            }

            if ((string) config('app.env') === 'production') {
                $this->error('Fixture provenance is forbidden in production — public-result publication refused.');

                return self::FAILURE;
            }

            $sourceState = GloSourceState::FixtureOnly;
        } elseif ($provenance === 'source-verified') {
            if ($provider !== 'official' || $provenanceReference === '') {
                $this->error('Verified provenance does not match an official GLO import — publication refused.');

                return self::FAILURE;
            }

            $sourceState = GloSourceState::OfficialSourceVerified;
        } else {
            $this->error('Result provenance is unknown or unsupported — public-result publication refused.');

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

        $cacheKey = sprintf(
            'glo:public-result:%s:%s:%s',
            $draw->getKey(),
            $fingerprint,
            strtolower($sourceState->value),
        );

        // Idempotent same-version publish: the key already embeds the
        // fingerprint, so re-publishing the identical version is a no-op success.
        // A DIFFERENT version for the same draw lives under a different key;
        // readers pick the current key only after an explicit new-version
        // publish. --force re-puts the same fingerprint (refresh TTL).
        $previous = Cache::get($cacheKey);
        $sameVersionAlreadyPublished = $previous !== null;

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

        // 60 minutes matches glo.result_experience.cache_ttl_seconds default;
        // key includes product|draw|result_version as required.
        Cache::put($cacheKey, $payload, (int) config('glo.result_experience.cache_ttl_seconds', 60) * 60);

        // Alias for "current draw" readers (still version-stamped).
        Cache::put('glo:public-result:current:'.$draw->getKey(), $cacheKey, (int) config('glo.result_experience.cache_ttl_seconds', 60) * 60);

        // Home aggregates that embed the current result must not serve a stale card.
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
                'forced' => (bool) $this->option('force'),
            ],
        ]);

        $out = [
            'draw_id' => (int) $draw->getKey(),
            'draw_number' => $draw->draw_number,
            'result_version' => $fingerprint,
            'source_state' => $sourceState->value,
            'cache_key' => $cacheKey,
            'already_published' => $sameVersionAlreadyPublished,
            'forced' => (bool) $this->option('force'),
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
