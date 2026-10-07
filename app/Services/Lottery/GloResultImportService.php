<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\ResultSourceType;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloResultImport;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * GLO result import service — provenance-tracked write of official/fixture
 * results onto the draw_results row.
 *
 * Providers: GloFixtureResultProvider (default mode) and
 * GloOfficialResultProvider (documented endpoints only). Selection follows
 * config('glo.official_source.mode').
 *
 * Guarantees:
 *  - NOT_CONFIGURED / failed provider payloads are recorded as GloResultImport
 *    rows and do NOT write draw_results.
 *  - Imported numbers are digit strings (leading zeros preserved).
 *  - Idempotent per draw+fingerprint: a re-import of the same payload
 *    returns the existing import row.
 *  - Audit: DataImport action, no secrets in metadata.
 */
class GloResultImportService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly GloFixtureResultProvider $fixtureProvider,
        private readonly GloOfficialResultProvider $officialProvider,
    ) {}

    public function provider(): GloResultProvider
    {
        $mode = (string) config('glo.official_source.mode', 'fixture');

        return $mode === 'official' ? $this->officialProvider : $this->fixtureProvider;
    }

    /**
     * Import a result for a draw. Returns the provenance row.
     *
     * @return array{import: GloResultImport, draw_result: DrawResult|null, payload: array<string, mixed>}
     */
    public function importForDraw(Draw $draw, ?User $actor = null, ?string $drawNumberOverride = null): array
    {
        $provider = $this->provider();
        $drawNumber = $drawNumberOverride ?? (string) $draw->draw_number;
        $payload = $provider->fetch($drawNumber);

        return $this->persistPayload($draw, $provider, $payload, $actor);
    }

    /**
     * Import without a local Draw row (latest fixture replay) — records
     * provenance only, returns null draw_result.
     *
     * @return array{import: GloResultImport, draw_result: DrawResult|null, payload: array<string, mixed>}
     */
    public function importLatest(?User $actor = null): array
    {
        $provider = $this->provider();
        $payload = $provider->fetch(null);

        // Latest fixture may not map to a local draw — find by fixture draw number.
        $draw = null;
        $fixtureDraw = (string) ($payload['draw_number'] ?? '');

        if ($fixtureDraw !== '') {
            $draw = Draw::query()
                ->where('draw_number', $fixtureDraw)
                ->first();
        }

        if ($draw === null) {
            // Provenance-only row (draw_id null).
            $import = $this->recordImport(null, $provider, $payload, $actor);

            return ['import' => $import, 'draw_result' => null, 'payload' => $payload];
        }

        return $this->persistPayload($draw, $provider, $payload, $actor);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{import: GloResultImport, draw_result: DrawResult|null, payload: array<string, mixed>}
     */
    private function persistPayload(Draw $draw, GloResultProvider $provider, array $payload, ?User $actor): array
    {
        $drawId = (int) $draw->getKey();
        $status = (string) ($payload['status'] ?? 'failed');

        if ($status !== 'imported') {
            // Honest negative: record provenance, do not write results.
            $import = $this->recordImport($drawId, $provider, $payload, $actor);

            return ['import' => $import, 'draw_result' => null, 'payload' => $payload];
        }

        $fingerprint = (string) ($payload['fingerprint'] ?? '');

        if ($fingerprint !== '') {
            $existing = GloResultImport::query()
                ->where('draw_id', $drawId)
                ->where('result_fingerprint', $fingerprint)
                ->where('status', 'imported')
                ->first();

            if ($existing !== null) {
                $result = DrawResult::query()->where('draw_id', $drawId)->first();

                return ['import' => $existing, 'draw_result' => $result, 'payload' => $payload];
            }
        }

        return $this->db->connection()->transaction(function () use ($drawId, $provider, $payload, $fingerprint, $actor): array {
            $tierKey = (string) config('glo.tiers.metadata_key', 'glo');

            $result = DrawResult::query()
                ->where('draw_id', $drawId)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'draw_id' => $drawId,
                'first_prize' => (string) $payload['first_prize'],
                'second_prize' => array_values($payload['second_prize'] ?? []),
                'third_prize' => array_values($payload['third_prize'] ?? []),
                'consolation_prizes' => $result->consolation_prizes ?? [],
                'all_numbers' => $result->all_numbers ?? [],
                'total_winners' => $result->total_winners ?? 0,
                'total_payout' => $result->total_payout ?? '0.00',
                'house_profit' => $result->house_profit ?? '0.00',
                'published_at' => $result->published_at ?? now(),
            ];

            $lane = is_array($result?->metadata[$tierKey] ?? null) ? $result->metadata[$tierKey] : [];
            $lane = array_merge($lane, (array) ($payload['tiers'] ?? []));

            if (($payload['n3'] ?? []) !== []) {
                $lane['n3'] = $payload['n3'];
            }

            $lane['import_fingerprint'] = $fingerprint;
            $lane['import_provider'] = $provider->name();
            $lane['imported_at'] = now()->toIso8601String();

            $metadata = is_array($result?->metadata) ? $result->metadata : [];
            $metadata[$tierKey] = $lane;
            $attributes['metadata'] = $metadata;

            if ($result === null) {
                $result = DrawResult::create($attributes);
            } else {
                $result->fill($attributes);
                $result->save();
            }

            $import = $this->recordImport($drawId, $provider, $payload, $actor);

            AuditLog::create([
                'user_id' => $actor?->getKey(),
                'action' => AuditAction::DataImport,
                'risk_level' => RiskLevel::High,
                'auditable_type' => DrawResult::class,
                'auditable_id' => $result->getKey(),
                'description' => 'glo_result_imported',
                'metadata' => [
                    'action_type' => 'glo_result_imported',
                    'provider' => $provider->name(),
                    'draw_id' => $drawId,
                    'import_reference' => $import->import_reference,
                    'result_fingerprint' => $fingerprint,
                    // Digit string — never int.
                    'first_prize' => (string) $payload['first_prize'],
                    'source_type' => ResultSourceType::Import->value,
                ],
            ]);

            return ['import' => $import, 'draw_result' => $result, 'payload' => $payload];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordImport(?int $drawId, GloResultProvider $provider, array $payload, ?User $actor): GloResultImport
    {
        $status = (string) ($payload['status'] ?? 'failed');
        $mode = (string) config('glo.official_source.mode', 'fixture');

        return GloResultImport::create([
            'import_reference' => 'GLOIMP-'.Str::upper(Str::random(16)),
            'draw_id' => $drawId,
            'provider' => $provider->name(),
            'mode' => $mode,
            'endpoint' => $payload['endpoint'] ?? null,
            'upstream_draw_id' => $payload['draw_number'] ?? null,
            'status' => $status,
            'result_fingerprint' => $payload['fingerprint'] ?? null,
            'payload_summary' => [
                'first_prize' => $payload['first_prize'] ?? null,
                'second_count' => count((array) ($payload['second_prize'] ?? [])),
                'third_count' => count((array) ($payload['third_prize'] ?? [])),
                'n3_groups' => array_keys((array) ($payload['n3'] ?? [])),
            ],
            'failure_reason' => $payload['failure_reason'] ?? null,
            'imported_by' => $actor?->getKey(),
            'imported_at' => now(),
        ]);
    }
}
