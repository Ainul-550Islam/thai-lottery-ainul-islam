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
use App\Services\Draw\DrawResultIngestionService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * GLO result import service — provenance-tracked intake of official/fixture
 * results.
 *
 * ============================================================================
 * P0 WRITE-BOUNDARY RESTORATION (this file was regressed by commit f5cec15)
 * ============================================================================
 *
 * WHAT THIS FILE DID WRONG
 * The restored/synced version of this file wrote `draw_results` directly:
 *
 *     $result = DrawResult::create($attributes);   // <-- write-boundary breach
 *
 * with the attributes including `'published_at' => $result->published_at ?? now()`.
 * That is three separate violations of the platform's financial-integrity
 * contract, and together they are the single most severe defect found in this
 * audit:
 *
 *  1. FOUR-EYES BYPASS. DrawResultIngestionService is the ONLY component that
 *     writes an ingested result in the `Pending` state, waiting for a second
 *     operator to confirm. Writing DrawResult directly skips the Pending state
 *     entirely, so a single operator — or an unattended command — can move an
 *     official result straight into the published form with no second pair of
 *     eyes. DrawResultConfirmationService was ALSO regressed in the same commit
 *     (its `ingested_by === $operatorId` guard was deleted), so the two
 *     regressions compose: the ingestion side stopped creating reviewable
 *     Pending records, and the confirmation side stopped refusing
 *     self-confirmation. There is currently no working four-eyes control on the
 *     GLO result path.
 *
 *  2. UNGATED PUBLICATION. `published_at` was set at import time. Every public
 *     surface that treats `published_at IS NOT NULL` as "the official result
 *     is live" (results pages, payout settlement, ticket checking) would serve
 *     and settle a number that no human had verified.
 *
 *  3. NO LIFECYCLE GUARD. The direct write never called
 *     DrawResultIngestionService::mayIngestIn($state), which is what refuses
 *     ingestion into a draw that is still Open, or one that has already been
 *     Settled. A result could therefore be written into a draw that is still
 *     accepting bets, or over a draw whose payouts had already been computed.
 *
 * Additionally, the `$fingerprint === ''` fail-closed guard was removed, so a
 * provider payload that arrived without a fingerprint fell straight through to
 * the write. A result whose integrity cannot be pinned by a fingerprint must be
 * refused, not persisted.
 *
 * WHAT THIS FILE DOES NOW
 * It is an INTAKE ADAPTER and nothing else. It owns no `draw_results` write, no
 * `published_at`, no `DrawResult` model interaction. Every import is handed to
 * DrawResultIngestionService::ingest(), which:
 *   - locks the draw row,
 *   - refuses ingestion outside a permitted lifecycle state,
 *   - canonicalises and fingerprints the payload,
 *   - files a `Pending` record that a DIFFERENT operator must confirm,
 *   - records the audit trail.
 *
 * The GloResultImport provenance row is still written here, because that row is
 * intake bookkeeping (which provider said what, when, with which fingerprint) and
 * is not a financial or publication fact.
 *
 * WHAT IS STILL NOT GATED HERE
 * Nothing in this file publishes. It cannot. That is the point: the only door
 * into publication is DrawResultConfirmationService::confirm(), which is the
 * second pair of eyes.
 *
 * Providers: GloFixtureResultProvider (fixture mode) and
 * GloOfficialResultProvider (documented public endpoints only), selected by
 * config('glo.official_source.mode'). See GloResultProviderChain for the
 * multi-provider fallback ladder.
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
     * Import a result for a draw.
     *
     * @return array{
     *     import: GloResultImport,
     *     draw_result: DrawResult|null,
     *     payload: array<string, mixed>,
     *     ingestion: array<string, mixed>|null
     * }
     */
    public function importForDraw(Draw $draw, ?User $actor = null, ?string $drawNumberOverride = null): array
    {
        $provider = $this->provider();
        $drawNumber = $drawNumberOverride ?? (string) $draw->draw_number;
        $payload = $provider->fetch($drawNumber);

        return $this->persistPayload($draw, $provider, $payload, $actor);
    }

    /**
     * Import without a local Draw row (latest provider replay) — records
     * provenance only and never writes a result.
     *
     * @return array{
     *     import: GloResultImport,
     *     draw_result: DrawResult|null,
     *     payload: array<string, mixed>,
     *     ingestion: array<string, mixed>|null
     * }
     */
    public function importLatest(?User $actor = null): array
    {
        $provider = $this->provider();
        $payload = $provider->fetch(null);

        $draw = null;
        $providerDraw = (string) ($payload['draw_number'] ?? '');

        if ($providerDraw !== '') {
            $draw = Draw::query()
                ->where('draw_number', $providerDraw)
                // Fall back to uuid: draw_number is not the only identity a
                // provider may echo back.
                ->orWhere('uuid', $providerDraw)
                ->first();
        }

        if ($draw === null) {
            // Provenance-only row (draw_id null). No result is written because
            // there is no draw to attach it to — and inventing one would be
            // worse than filing nothing.
            $import = $this->recordImport(null, $provider, $payload, $actor);

            return [
                'import' => $import,
                'draw_result' => null,
                'payload' => $payload,
                'ingestion' => null,
            ];
        }

        return $this->persistPayload($draw, $provider, $payload, $actor);
    }

    /**
     * File the provider payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     import: GloResultImport,
     *     draw_result: DrawResult|null,
     *     payload: array<string, mixed>,
     *     ingestion: array<string, mixed>|null
     * }
     */
    private function persistPayload(Draw $draw, GloResultProvider $provider, array $payload, ?User $actor): array
    {
        $drawId = (int) $draw->getKey();
        $status = (string) ($payload['status'] ?? 'failed');

        // ── Not an imported payload: record the honest negative, write nothing.
        if ($status !== 'imported') {
            $import = $this->recordImport($drawId, $provider, $payload, $actor);

            return [
                'import' => $import,
                'draw_result' => null,
                'payload' => $payload,
                'ingestion' => null,
            ];
        }

        $fingerprint = trim((string) ($payload['fingerprint'] ?? ''));

        // ── FAIL CLOSED on a missing fingerprint.
        //
        // This guard was deleted in the regression. Without it, a payload whose
        // integrity cannot be pinned by a fingerprint would be persisted, and
        // there would be no way afterwards to prove which bytes were ingested.
        // `imported` with no fingerprint is a provider contract violation, not a
        // reason to relax the check.
        if ($fingerprint === '') {
            $payload['status'] = 'failed';
            $payload['failure_reason'] = 'Provider returned an imported payload without a result fingerprint.';
            $import = $this->recordImport($drawId, $provider, $payload, $actor);

            return [
                'import' => $import,
                'draw_result' => null,
                'payload' => $payload,
                'ingestion' => null,
            ];
        }

        // ── Idempotent replay: same draw + same fingerprint already filed.
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

        // ── Hand the payload to the ONE component allowed to write an ingested
        //    result. It owns the row lock, the lifecycle guard, the canonical
        //    fingerprint and the Pending state. This file does not write
        //    draw_results, does not set published_at, and cannot publish.
        $ingestion = $this->ingestion->ingest(
            $drawId,
            [
                'first_prize' => (string) ($payload['first_prize'] ?? ''),
                'bottom_two' => (string) ($payload['bottom_two'] ?? ''),
                'tiers' => (array) ($payload['tiers'] ?? []),
                'n3' => (array) ($payload['n3'] ?? []),
                'provider_fingerprint' => $fingerprint,
            ],
            source: sprintf('glo_import:%s', $provider->name()),
            actorUserId: $actor?->getKey() === null ? null : (int) $actor->getKey(),
        );

        $import = $this->recordImport($drawId, $provider, $payload, $actor, $ingestion['fingerprint'] ?? null);

        $this->recordAudit($drawId, $provider, $import, $ingestion, $fingerprint, $actor);

        return [
            'import' => $import,
            'draw_result' => null,
            'payload' => $payload,
            'ingestion' => $ingestion,
        ];
    }

    /**
     * Write the intake provenance row.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordImport(
        ?int $drawId,
        GloResultProvider $provider,
        array $payload,
        ?User $actor,
        ?string $normalizedFingerprint = null,
    ): GloResultImport {
        $status = (string) ($payload['status'] ?? 'failed');
        $mode = (string) config('glo.official_source.mode', 'fixture');

        return GloResultImport::query()->create([
            'draw_id' => $drawId,
            'provider' => $provider->name(),
            'mode' => $mode,
            'status' => $status,
            'endpoint' => isset($payload['endpoint']) ? (string) $payload['endpoint'] : null,
            'draw_number' => isset($payload['draw_number']) ? (string) $payload['draw_number'] : null,
            'result_fingerprint' => $normalizedFingerprint
                ?? (isset($payload['fingerprint']) ? (string) $payload['fingerprint'] : null),
            'failure_reason' => isset($payload['failure_reason']) ? (string) $payload['failure_reason'] : null,
            'import_reference' => 'glo-'.Str::lower(Str::ulid()->toBase32()),
            'imported_by' => $actor?->getKey(),
            'imported_at' => now(),
            'payload' => $this->redact($payload),
        ]);
    }

    /**
     * Audit the INTAKE. Explicitly not an audit of a publication — that is
     * DrawResultConfirmationService's record to write, by a different operator.
     *
     * @param  array<string, mixed>  $ingestion
     */
    private function recordAudit(
        int $drawId,
        GloResultProvider $provider,
        GloResultImport $import,
        array $ingestion,
        string $providerFingerprint,
        ?User $actor,
    ): void {
        AuditLog::create([
            'user_id' => $actor?->getKey(),
            'action' => AuditAction::DataImport,
            'risk_level' => RiskLevel::High,
            'auditable_type' => GloResultImport::class,
            'auditable_id' => $import->getKey(),
            'description' => 'glo_result_ingested_pending_confirmation',
            'metadata' => [
                'action_type' => 'glo_result_ingested_pending_confirmation',
                'provider' => $provider->name(),
                'draw_id' => $drawId,
                'import_reference' => $import->import_reference,
                'provider_fingerprint' => $providerFingerprint,
                'ingestion_fingerprint' => $ingestion['fingerprint'] ?? null,
                'ingestion_status' => $ingestion['status'] ?? null,
                // Digit string — never int. Leading zeros are part of the number.
                'first_prize' => (string) ($ingestion['first_prize'] ?? ''),
                'bottom_two' => (string) ($ingestion['bottom_two'] ?? ''),
                'source_type' => ResultSourceType::Import->value,
                // Stated in the record itself so an auditor does not have to
                // infer it: this row is an intake, not a publication.
                'publication_state' => 'pending_second_operator_confirmation',
            ],
        ]);
    }

    /**
     * Strip anything credential-shaped before a provider payload is persisted.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        $forbidden = ['authorization', 'api_key', 'apikey', 'secret', 'token', 'password', 'signature'];

        $clean = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $clean;
    }
}
