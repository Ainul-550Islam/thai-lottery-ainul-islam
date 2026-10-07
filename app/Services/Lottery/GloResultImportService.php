<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloResultImport;
use App\Models\User;
use App\Services\Draw\DrawResultIngestionService;
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
        private readonly DrawResultIngestionService $ingestion,
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
                ->orWhere('uuid', $fixtureDraw)
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
            $import = $this->recordImport($drawId, $provider, $payload, $actor);

            return ['import' => $import, 'draw_result' => null, 'payload' => $payload, 'ingestion' => null];
        }

        $fingerprint = trim((string) ($payload['fingerprint'] ?? ''));

        if ($fingerprint === '') {
            $payload['status'] = 'failed';
            $payload['failure_reason'] = 'Provider returned an imported payload without a result fingerprint.';
            $import = $this->recordImport($drawId, $provider, $payload, $actor);

            return ['import' => $import, 'draw_result' => null, 'payload' => $payload, 'ingestion' => null];
        }

        $existing = GloResultImport::query()
            ->where('draw_id', $drawId)
            ->where('result_fingerprint', $fingerprint)
            ->where('status', 'imported')
            ->first();

        if ($existing instanceof GloResultImport) {
            return [
                'import' => $existing,
                'draw_result' => null,
                'payload' => $payload,
                'ingestion' => $this->ingestion->currentIngestion($drawId),
            ];
        }

        return $this->db->connection()->transaction(function () use ($drawId, $provider, $payload, $actor): array {
            $import = $this->recordImport($drawId, $provider, $payload, $actor);
            $firstPrize = (string) ($payload['first_prize'] ?? '');
            $ingestion = $this->ingestion->ingest(
                $drawId,
                ['first_prize' => $firstPrize, 'bottom_two' => substr($firstPrize, -2)],
                'glo:'.$provider->name().':'.$import->import_reference,
                $actor?->getKey() !== null ? (int) $actor->getKey() : null,
            );

            return ['import' => $import, 'draw_result' => null, 'payload' => $payload, 'ingestion' => $ingestion];
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
