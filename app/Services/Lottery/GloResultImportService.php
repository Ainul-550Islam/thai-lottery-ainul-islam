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
 * The restored/synced version wrote the authoritative draw_results row directly
 * and set published_at at import time, crossing the result write boundary.
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
 * GloResultProviderChain selects the configured ordered source ladder. The
 * chain uses GloOfficialResultProvider for documented public endpoints and
 * GloFixtureResultProvider only when fixture mode or an explicitly permitted
 * fixture fallback is configured.
 */
class GloResultImportService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly GloResultProviderChain $providerChain,
        private readonly DrawResultIngestionService $ingestion,
    ) {}

    public function provider(): GloResultProvider
    {
        return $this->providerChain;
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
        $providerDrawValue = $payload['draw_number'] ?? null;
        $providerDraw = is_string($providerDrawValue) ? trim($providerDrawValue) : '';

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
            // worse than filing nothing. An exact replay of the same upstream
            // fingerprint reuses its existing provenance row.
            $fingerprint = $payload['fingerprint'] ?? null;

            if ((string) ($payload['status'] ?? '') === 'imported'
                && is_string($fingerprint)
                && preg_match('/\A[a-f0-9]{64}\z/iD', $fingerprint) === 1) {
                $existing = GloResultImport::query()
                    ->whereNull('draw_id')
                    ->where('result_fingerprint', strtolower($fingerprint))
                    ->where('status', 'imported')
                    ->first();

                if ($existing instanceof GloResultImport) {
                    return [
                        'import' => $existing,
                        'draw_result' => null,
                        'payload' => $payload,
                        'ingestion' => null,
                    ];
                }
            }

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
        return $this->db->transaction(function () use ($draw, $provider, $payload, $actor): array {
            $lockedDraw = Draw::query()->lockForUpdate()->find((int) $draw->getKey());

            if (! $lockedDraw instanceof Draw) {
                throw new \RuntimeException('The draw was deleted before result import could be recorded.');
            }

            return $this->persistLockedPayload($lockedDraw, $provider, $payload, $actor);
        });
    }

    /**
     * The caller must hold the draw row lock for the complete idempotency,
     * ingestion, provenance and audit sequence.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     import: GloResultImport,
     *     draw_result: DrawResult|null,
     *     payload: array<string, mixed>,
     *     ingestion: array<string, mixed>|null
     * }
     */
    private function persistLockedPayload(Draw $draw, GloResultProvider $provider, array $payload, ?User $actor): array
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

        $fingerprintValue = $payload['fingerprint'] ?? null;
        $fingerprint = is_string($fingerprintValue) ? strtolower(trim($fingerprintValue)) : '';

        // ── FAIL CLOSED on a missing or malformed fingerprint.
        //
        // This guard was deleted in the regression. Without it, a payload whose
        // integrity cannot be pinned by a fingerprint would be persisted, and
        // there would be no way afterwards to prove which bytes were ingested.
        // An absent or malformed fingerprint is a provider contract violation,
        // not a reason to relax the check.
        if (preg_match('/\A[a-f0-9]{64}\z/iD', $fingerprint) !== 1) {
            $payload['status'] = 'failed';
            $payload['failure_reason'] = 'Provider returned an imported payload without a valid SHA-256 result fingerprint.';
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

        $actorId = $actor?->getKey();
        $makerExists = is_int($actorId)
            ? $actorId > 0 && User::query()->whereKey($actorId)->exists()
            : (is_string($actorId) && ctype_digit($actorId)
                && (int) $actorId > 0 && User::query()->whereKey((int) $actorId)->exists());

        if (! $makerExists || ! $actor?->isActive()) {
            $payload['status'] = 'refused';
            $payload['failure_reason'] = 'A persisted active operator is required to attribute the result maker before staging.';
            $import = $this->recordImport($drawId, $provider, $payload, $actor);

            return [
                'import' => $import,
                'draw_result' => null,
                'payload' => $payload,
                'ingestion' => null,
            ];
        }

        // ── Hand the payload to the ONE component allowed to write an ingested
        //    result. It owns the row lock, the lifecycle guard, the canonical
        //    fingerprint and the Pending state. This file does not write
        //    draw_results, does not set published_at, and cannot publish.
        try {
            $ingestion = $this->ingestion->ingest(
                $drawId,
                [
                    'first_prize' => (string) ($payload['first_prize'] ?? ''),
                    'bottom_two' => $this->announcedBottomTwo($payload),
                    'second_prize' => (array) ($payload['second_prize'] ?? []),
                    'third_prize' => (array) ($payload['third_prize'] ?? []),
                    'tiers' => (array) ($payload['tiers'] ?? []),
                    'n3' => (array) ($payload['n3'] ?? []),
                    'provider' => $this->sourceName($provider, $payload),
                    'provider_fingerprint' => $fingerprint,
                    'upstream_draw_id' => (string) ($payload['draw_number'] ?? ''),
                ],
                source: sprintf('glo_import:%s', $this->sourceName($provider, $payload)),
                actorUserId: $actor?->getKey() === null ? null : (int) $actor->getKey(),
            );
        } catch (DrawResultException $exception) {
            // A provider response that fails canonicalization or lifecycle
            // validation is a recorded refusal, never a successful import.
            // Store only the bounded/redacted reason and provider fingerprint;
            // the authoritative result table remains untouched.
            $payload['status'] = 'refused';
            $payload['failure_reason'] = $exception->getMessage();
            $import = $this->recordImport($drawId, $provider, $payload, $actor);

            return [
                'import' => $import,
                'draw_result' => null,
                'payload' => $payload,
                'ingestion' => null,
            ];
        }

        $import = $this->recordImport($drawId, $provider, $payload, $actor, $ingestion['fingerprint'] ?? null);

        $this->recordAudit($drawId, $import, $ingestion, $fingerprint, $actor);

        return [
            'import' => $import,
            'draw_result' => null,
            'payload' => $payload,
            'ingestion' => $ingestion,
        ];
    }

    /**
     * Read the separately announced two-digit result from the provider payload.
     *
     * The official provider shape carries it as the single `tiers.last_two`
     * prize. This method accepts an explicit bottom_two string or that exact
     * one-item official list; it never derives the value from first_prize.
     *
     * @param  array<string, mixed>  $payload
     */
    private function announcedBottomTwo(array $payload): string
    {
        if (array_key_exists('bottom_two', $payload)) {
            return is_string($payload['bottom_two']) ? trim($payload['bottom_two']) : '';
        }

        $tiers = $payload['tiers'] ?? null;
        $lastTwo = is_array($tiers) ? ($tiers['last_two'] ?? null) : null;

        if (! is_array($lastTwo) || count($lastTwo) !== 1 || ! is_string($lastTwo[0] ?? null)) {
            return '';
        }

        return trim($lastTwo[0]);
    }

    /**
     * Resolve the source label used in provenance and ingestion metadata.
     *
     * The chain owns the source label on its returned payload. A direct provider
     * owns its own label, so a provider cannot spoof another source through JSON.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sourceName(GloResultProvider $provider, array $payload): string
    {
        if ($provider->name() !== 'chain') {
            return $provider->name();
        }

        $source = $payload['provider'] ?? null;

        return is_string($source) && trim($source) !== '' ? trim($source) : 'chain';
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

        $sourceName = $this->sourceName($provider, $payload);
        $providerFingerprint = $payload['fingerprint'] ?? null;
        $providerFingerprint = is_string($providerFingerprint)
            && preg_match('/\A[a-f0-9]{64}\z/iD', $providerFingerprint) === 1
                ? strtolower($providerFingerprint)
                : null;
        $endpoint = $payload['endpoint'] ?? null;
        $failureReason = $payload['failure_reason'] ?? null;

        return GloResultImport::query()->create([
            'draw_id' => $drawId,
            'provider' => $sourceName,
            'mode' => $mode,
            'status' => $status,
            'endpoint' => is_string($endpoint) ? mb_substr($this->redactString($endpoint), 0, 255) : null,
            'upstream_draw_id' => $this->upstreamDrawId($payload),
            // This stable source fingerprint is the idempotency key. The
            // ingestion service's separately canonicalized fingerprint is
            // retained in the bounded summary rather than replacing it.
            'result_fingerprint' => $providerFingerprint,
            'payload_summary' => $this->payloadSummary($payload, $normalizedFingerprint),
            'failure_reason' => is_string($failureReason)
                ? mb_substr($this->redactString($failureReason), 0, 500)
                : null,
            'import_reference' => 'glo-'.Str::lower(Str::ulid()->toBase32()),
            'imported_by' => $actor?->getKey(),
            'imported_at' => now(),
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
                'provider' => (string) $import->provider,
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
     * Keep the upstream draw identifier inside the model column's fixed width.
     *
     * @param  array<string, mixed>  $payload
     */
    private function upstreamDrawId(array $payload): ?string
    {
        $drawNumber = $payload['draw_number'] ?? null;

        return is_string($drawNumber) && trim($drawNumber) !== ''
            ? mb_substr(trim($drawNumber), 0, 64)
            : null;
    }

    /**
     * Persist only a bounded digest; never the raw upstream document.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function payloadSummary(array $payload, ?string $normalizedFingerprint): array
    {
        $firstPrize = $payload['first_prize'] ?? null;
        $secondPrizes = $payload['second_prize'] ?? null;
        $thirdPrizes = $payload['third_prize'] ?? null;
        $providerFingerprint = $payload['fingerprint'] ?? null;
        $ingestionFingerprint = is_string($normalizedFingerprint)
            && preg_match('/\A[a-f0-9]{64}\z/iD', $normalizedFingerprint) === 1
                ? strtolower($normalizedFingerprint)
                : null;

        $bottomTwo = $this->announcedBottomTwo($payload);

        return [
            'draw_number' => $this->upstreamDrawId($payload),
            'first_prize' => is_string($firstPrize) && preg_match('/\A\d{6}\z/D', $firstPrize) === 1
                ? $firstPrize
                : null,
            'bottom_two' => preg_match('/\A\d{2}\z/D', $bottomTwo) === 1 ? $bottomTwo : null,
            'second_prize_count' => is_array($secondPrizes) ? count($secondPrizes) : 0,
            'third_prize_count' => is_array($thirdPrizes) ? count($thirdPrizes) : 0,
            'n3_counts' => $this->countGroups($payload['n3'] ?? null),
            'tier_counts' => $this->countGroups($payload['tiers'] ?? null),
            'provider_fingerprint' => is_string($providerFingerprint)
                && preg_match('/\A[a-f0-9]{64}\z/iD', $providerFingerprint) === 1
                    ? strtolower($providerFingerprint)
                    : null,
            'ingestion_fingerprint' => $ingestionFingerprint,
            'source_attempts' => $this->sourceAttemptSummary($payload['ladder'] ?? null),
        ];
    }

    /**
     * @return list<array{source: string, outcome: string, reason: string|null}>
     */
    private function sourceAttemptSummary(mixed $attempts): array
    {
        if (! is_array($attempts)) {
            return [];
        }

        $summary = [];

        foreach (array_slice($attempts, 0, 8) as $attempt) {
            if (! is_array($attempt)) {
                continue;
            }

            $source = $attempt['source'] ?? null;
            $outcome = $attempt['outcome'] ?? null;
            $reason = $attempt['reason'] ?? null;

            if (! is_string($source) || ! is_string($outcome)) {
                continue;
            }

            $summary[] = [
                'source' => mb_substr($source, 0, 32),
                'outcome' => mb_substr($outcome, 0, 16),
                'reason' => is_string($reason)
                    ? mb_substr($this->redactString($reason), 0, 160)
                    : null,
            ];
        }

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    private function countGroups(mixed $groups): array
    {
        if (! is_array($groups)) {
            return [];
        }

        $counts = [];

        foreach (array_slice($groups, 0, 16, true) as $key => $values) {
            $name = mb_substr((string) $key, 0, 32);

            if ($name === '') {
                continue;
            }

            $counts[$name] = is_array($values) ? count($values) : 0;
        }

        return $counts;
    }

    /** Redact credential-shaped query parameters and key/value pairs. */
    private function redactString(string $value): string
    {
        $redacted = preg_replace(
            '/([?&](?:authorization|access[_-]?token|token|api[_-]?key|client[_-]?secret|secret|password|signature)=)[^&#\\s]*/i',
            '$1[redacted]',
            $value,
        ) ?? $value;

        $redacted = preg_replace(
            '/\bauthorization\s*[:=]\s*(?:bearer\s+)?[^\s,;&]+/i',
            'authorization=[redacted]',
            $redacted,
        ) ?? $redacted;

        return preg_replace(
            '/\b(access[_-]?token|token|api[_-]?key|client[_-]?secret|secret|password|signature)\s*[:=]\s*[^\s,;&]+/i',
            '$1=[redacted]',
            $redacted,
        ) ?? $redacted;
    }
}
