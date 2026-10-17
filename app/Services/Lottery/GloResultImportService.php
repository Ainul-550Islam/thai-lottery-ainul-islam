<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\ResultSourceType;
use App\Enums\RiskLevel;
use App\Exceptions\DrawResultException;
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
 * Fetching goes through GloResultProviderChain — the ordered fallback
 * ladder over the fixture and official providers (documented public
 * endpoints only) — and the provenance label is always the payload's own
 * `provider` field: the source that ANSWERED, never the object asked.
 */
class GloResultImportService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly GloResultProviderChain $chain,
        private readonly DrawResultIngestionService $ingestion,
    ) {}

    public function provider(): GloResultProvider
    {
        return $this->chain;
    }

    /**
     * Fetch through the ladder, applying the ONE remap the chain must not
     * make: when the deployment runs in official mode with no configured
     * priority ladder and EVERY attempt ended `not_configured`, the honest
     * answer for the import is `not_configured` (a deployment fact), not
     * `failed` (an outage that would page an operator). The chain's own
     * terminal vocabulary is always `failed`; this translation belongs to
     * the importer, which knows the mode.
     *
     * @return array<string, mixed>
     */
    private function fetchPayload(?string $drawNumber): array
    {
        $payload = $this->chain->fetch($drawNumber);

        if (($payload['status'] ?? null) !== 'failed') {
            return $payload;
        }

        $priority = config('glo.sources.priority', []);
        $officialMode = strtolower(trim((string) config('glo.official_source.mode', 'fixture'))) === 'official';

        if (! $officialMode || ! is_array($priority) || $priority !== []) {
            return $payload;
        }

        $attempts = $this->chain->attempts();

        if ($attempts === []) {
            return $payload;
        }

        foreach ($attempts as $attempt) {
            // A not_configured attempt is recorded either as the canonical
            // 'not configured' sentence or with its machine-code prefix
            // ('not_configured: <provider detail>'). Both are the deployment
            // fact this remap recognises; anything else (a genuine failure,
            // a circuit skip) blocks the remap.
            $reason = (string) ($attempt['reason'] ?? '');

            if (($attempt['outcome'] ?? null) !== 'skipped'
                || ! (str_contains($reason, 'not configured') || str_contains($reason, 'not_configured'))) {
                return $payload;
            }
        }

        $payload['status'] = 'not_configured';

        return $payload;
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
        $drawNumber = $drawNumberOverride ?? (string) $draw->draw_number;
        $payload = $this->fetchPayload($drawNumber);

        return $this->persistPayload($draw, $payload, $actor);
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
        $payload = $this->fetchPayload(null);

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
            $import = $this->recordImport(null, $payload, $actor);

            return [
                'import' => $import,
                'draw_result' => null,
                'payload' => $payload,
                'ingestion' => null,
            ];
        }

        return $this->persistPayload($draw, $payload, $actor);
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
    private function persistPayload(Draw $draw, array $payload, ?User $actor): array
    {
        $drawId = (int) $draw->getKey();
        $status = (string) ($payload['status'] ?? 'failed');
        $providerLabel = $this->providerLabel($payload);

        // ── Not an imported payload: record the honest negative, write nothing.
        if ($status !== 'imported') {
            $import = $this->recordImport($drawId, $payload, $actor);

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
            $payload['failure_reason'] = 'Provider returned an imported payload without a result fingerprint; refusing to stage a provider payload with no result fingerprint to pin.';
            $import = $this->recordImport($drawId, $payload, $actor);

            return [
                'import' => $import,
                'draw_result' => null,
                'payload' => $payload,
                'ingestion' => null,
            ];
        }

        // ── Idempotent replay: the canonical fingerprint of the numbers this
        //    payload carries already matches the currently staged ingestion.
        //    The comparison is over the CANONICAL pair (first prize + the
        //    independent bottom two), never the provider's own fingerprint:
        //    two providers can fingerprint the same numbers differently, and
        //    what must not be staged twice is the RESULT.
        $bottomTwo = $this->bottomTwoOf($payload);
        $canonicalFingerprint = DrawResultIngestionService::fingerprintFor([
            'first_prize' => (string) ($payload['first_prize'] ?? ''),
            'bottom_two' => $bottomTwo,
        ]);
        $current = $this->ingestion->currentIngestion($drawId);

        if (is_array($current)
            && DrawResultIngestionService::fingerprintFor([
                'first_prize' => (string) ($current['first_prize'] ?? ''),
                'bottom_two' => (string) ($current['bottom_two'] ?? ''),
            ]) === $canonicalFingerprint) {
            $existing = GloResultImport::query()
                ->where('draw_id', $drawId)
                ->where('status', 'imported')
                ->latest('id')
                ->first();

            if ($existing instanceof GloResultImport) {
                return [
                    'import' => $existing,
                    'draw_result' => null,
                    'payload' => $payload,
                    'ingestion' => $current,
                ];
            }
        }

        // ── Hand the payload to the ONE component allowed to write an ingested
        //    result. It owns the row lock, the lifecycle guard, the canonical
        //    fingerprint and the Pending state. This file does not write
        //    draw_results, does not set published_at, and cannot publish.
        //
        //    A lifecycle refusal (the draw already published, or is otherwise
        //    past input) is an EXPECTED operator outcome, so it is filed as a
        //    `refused` provenance row rather than raised. Any other domain
        //    failure is a real error and propagates.
        try {
            $ingestion = $this->ingestion->ingest(
                $drawId,
                [
                    'first_prize' => (string) ($payload['first_prize'] ?? ''),
                    'bottom_two' => $bottomTwo,
                    'tiers' => (array) ($payload['tiers'] ?? []),
                    'n3' => (array) ($payload['n3'] ?? []),
                    'provider' => $providerLabel,
                    'provider_fingerprint' => $fingerprint,
                ],
                source: sprintf('glo_import:%s', $providerLabel),
                actorUserId: $actor?->getKey() === null ? null : (int) $actor->getKey(),
            );
        } catch (DrawResultException $exception) {
            if ($exception->errorCode() !== DrawResultException::CODE_CONFIRMATION_FORBIDDEN) {
                throw $exception;
            }

            $payload['status'] = 'refused';
            $payload['failure_reason'] = 'Import refused: '.$exception->getMessage();
            $import = $this->recordImport($drawId, $payload, $actor);

            return [
                'import' => $import,
                'draw_result' => null,
                'payload' => $payload,
                'ingestion' => null,
            ];
        }

        $import = $this->recordImport($drawId, $payload, $actor, $ingestion['fingerprint'] ?? null);

        $this->recordAudit($drawId, $import, $ingestion, $fingerprint, $actor);

        return [
            'import' => $import,
            'draw_result' => null,
            'payload' => $payload,
            'ingestion' => $ingestion,
        ];
    }

    /**
     * The provenance label: the provider that ANSWERED, from the payload
     * itself, falling back to the chain's name when a payload omits it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function providerLabel(array $payload): string
    {
        $provider = $payload['provider'] ?? null;

        return is_string($provider) && trim($provider) !== '' ? trim($provider) : $this->chain->name();
    }

    /**
     * The independent bottom-two prize: the payload's `tiers.last_two[0]`,
     * with a flat `bottom_two` as fallback. NEVER derived from the first
     * prize — they are independent draws, and deriving one from the other
     * would fabricate a number.
     *
     * @param  array<string, mixed>  $payload
     */
    private function bottomTwoOf(array $payload): string
    {
        $tiers = is_array($payload['tiers'] ?? null) ? $payload['tiers'] : [];
        $lastTwo = is_array($tiers['last_two'] ?? null) ? $tiers['last_two'] : [];

        if (isset($lastTwo[0]) && is_scalar($lastTwo[0]) && (string) $lastTwo[0] !== '') {
            return (string) $lastTwo[0];
        }

        return isset($payload['bottom_two']) && is_scalar($payload['bottom_two'])
            ? (string) $payload['bottom_two']
            : '';
    }

    /**
     * Write the intake provenance row.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordImport(
        ?int $drawId,
        array $payload,
        ?User $actor,
        ?string $normalizedFingerprint = null,
    ): GloResultImport {
        $status = (string) ($payload['status'] ?? 'failed');
        $mode = (string) config('glo.official_source.mode', 'fixture');
        $payloadFingerprint = isset($payload['fingerprint']) && is_scalar($payload['fingerprint'])
            ? trim((string) $payload['fingerprint'])
            : '';

        return GloResultImport::query()->create([
            'draw_id' => $drawId,
            'provider' => $this->providerLabel($payload),
            'mode' => $mode,
            'status' => $status,
            'endpoint' => isset($payload['endpoint']) && is_scalar($payload['endpoint']) ? (string) $payload['endpoint'] : null,
            'upstream_draw_id' => isset($payload['draw_number']) && is_scalar($payload['draw_number']) ? (string) $payload['draw_number'] : null,
            'result_fingerprint' => $normalizedFingerprint
                ?? ($payloadFingerprint !== '' ? $payloadFingerprint : null),
            'failure_reason' => isset($payload['failure_reason']) && is_scalar($payload['failure_reason']) ? (string) $payload['failure_reason'] : null,
            'import_reference' => 'glo-'.Str::lower(Str::ulid()->toBase32()),
            'imported_by' => $actor?->getKey(),
            'imported_at' => now(),
            'payload_summary' => $this->payloadSummary($payload),
        ]);
    }

    /**
     * A bounded digest of the payload for the provenance row. The full
     * payload is deliberately NOT persisted (payload_summary is the schema),
     * and no credential-shaped key is carried into it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function payloadSummary(array $payload): array
    {
        return [
            'status' => (string) ($payload['status'] ?? 'failed'),
            'draw_number' => isset($payload['draw_number']) && is_scalar($payload['draw_number']) ? (string) $payload['draw_number'] : null,
            'first_prize' => isset($payload['first_prize']) && is_scalar($payload['first_prize']) ? (string) $payload['first_prize'] : null,
            'bottom_two' => $this->bottomTwoOf($payload),
            'second_prize_count' => is_array($payload['second_prize'] ?? null) ? count($payload['second_prize']) : 0,
            'third_prize_count' => is_array($payload['third_prize'] ?? null) ? count($payload['third_prize']) : 0,
            'provider_fingerprint' => isset($payload['fingerprint']) && is_scalar($payload['fingerprint']) ? (string) $payload['fingerprint'] : null,
        ];
    }

    /**
     * Audit the INTAKE. Explicitly not an audit of a publication — that is
     * DrawResultConfirmationService's record to write, by a different operator.
     *
     * @param  array<string, mixed>  $ingestion
     */
    private function recordAudit(
        int $drawId,
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
                'provider' => $import->provider,
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
