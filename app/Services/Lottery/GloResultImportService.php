<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\DrawStatus;
use App\Exceptions\DrawResultException;
use App\Enums\ResultSourceType;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloResultImport;
use App\Models\User;
use App\Services\Draw\DrawLifecycleService;
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
        private readonly GloResultProviderChain $chain,
        private readonly DrawResultIngestionService $ingestion,
        private readonly DrawLifecycleService $lifecycle,
    ) {}

    /**
     * The source the import path asks.
     *
     * Always the ladder: `GloResultProviderChain` already resolves
     * `glo.sources.priority` (and the legacy `glo.official_source.mode`) into an
     * order and walks it, so choosing a single provider here would bypass the
     * breaker, the skip recording and the fall-through rules.
     */
    public function provider(): GloResultProvider
    {
        return $this->chain;
    }

    /**
     * The name written to the provenance row.
     *
     * The ladder's own name is `chain`, which describes the transport rather
     * than the publication. What a reader of `glo_result_imports.provider` needs
     * is which source actually answered - and only the payload knows that.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sourceLabel(GloResultProvider $provider, array $payload): string
    {
        $answered = $payload['provider'] ?? null;

        return is_string($answered) && $answered !== '' ? $answered : $provider->name();
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
     * Fetch a specific draw from the official provider without recording it.
     * The command's dry run uses exactly the same validation as the live intake.
     *
     * @return array<string, mixed>
     */
    public function previewOfficialForDraw(Draw $draw): array
    {
        $number = trim((string) $draw->draw_number);
        if ($number === '') {
            return ['status' => 'failed', 'provider' => 'official', 'failure_reason' => 'The local draw has no provider identity.'];
        }

        $payload = $this->officialProvider->fetch($number);
        if (($payload['status'] ?? null) === 'imported') {
            $returned = $payload['draw_number'] ?? null;
            $bottom = $payload['bottom_two'] ?? null;
            if (! is_string($returned) || ! hash_equals($number, $returned)
                || ! is_string($bottom) || preg_match('/\A\d{2}\z/D', $bottom) !== 1) {
                $payload['status'] = 'failed';
                $payload['failure_reason'] = 'The official response did not identify this draw and its independent bottom-two prize.';
            }
        }

        return $payload;
    }

    /**
     * Official-only intake for the fetch command. It cannot publish: successful
     * results use the same provenance/ingestion writer as every GLO import.
     * A pending card is never silently replaced by an unattended fetch.
     *
     * @return array{import: GloResultImport, draw_result: DrawResult|null, payload: array<string, mixed>, ingestion: array<string, mixed>|null}
     */
    public function importOfficialForDraw(Draw $draw, User $actor): array
    {
        $payload = $this->previewOfficialForDraw($draw);

        return $this->db->connection()->transaction(function () use ($draw, $actor, $payload): array {
            $locked = Draw::query()->lockForUpdate()->findOrFail($draw->getKey());
            if (! in_array($locked->status, [DrawStatus::Closed, DrawStatus::Drawing], true)
                || $locked->scheduled_at === null || $locked->scheduled_at->isFuture()) {
                throw new \RuntimeException('The draw is not eligible for result intake.');
            }
            if ($this->ingestion->pendingIngestion((int) $locked->getKey()) !== null) {
                throw new \RuntimeException('A result is already awaiting review; automated fetch will not replace it.');
            }

            $outcome = $this->persistPayload($locked, $this->officialProvider, $payload, $actor);
            if (($outcome['import']->status ?? null) === 'imported'
                && is_array($outcome['ingestion'])
                && ($outcome['ingestion']['status'] ?? null) === 'pending'
                && $locked->status === DrawStatus::Closed) {
                $this->lifecycle->markResultPending($locked->fresh());
            }

            return $outcome;
        });
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
        $ingestionPayload = [
            'first_prize' => (string) ($payload['first_prize'] ?? ''),
            'bottom_two' => (string) ($payload['bottom_two'] ?? ''),
            'tiers' => (array) ($payload['tiers'] ?? []),
            'n3' => (array) ($payload['n3'] ?? []),
            'provider' => $this->sourceLabel($provider, $payload),
            'provider_fingerprint' => $fingerprint,
        ];
        // Preserve all provider prize facts for validation and later review.
        // Do not coerce malformed lists into arrays: ingestion must refuse them.
        foreach (['second_prize', 'third_prize', 'consolation_prizes'] as $field) {
            if (array_key_exists($field, $payload)) {
                $ingestionPayload[$field] = $payload[$field];
            }
        }

        try {
            $ingestion = $this->ingestion->ingest(
                $drawId,
                $ingestionPayload,
                source: sprintf('glo_import:%s', $this->sourceLabel($provider, $payload)),
                actorUserId: $actor?->getKey() === null ? null : (int) $actor->getKey(),
            );
        } catch (DrawResultException $exception) {
            // The payload was fine; the DRAW would not take it. That is filed as
            // `refused` - its own status, with its own sentence - because an
            // operator reading this row must look at the draw's lifecycle, not
            // at glo.or.th. Nothing was written to draw_results.
            $payload['status'] = 'refused';
            $payload['failure_reason'] = sprintf(
                'Result refused by the draw lifecycle guard: %s',
                $exception->getMessage(),
            );

            return [
                'import' => $this->recordImport($drawId, $provider, $payload, $actor),
                'draw_result' => null,
                'payload' => $payload,
                'ingestion' => null,
            ];
        }

        $import = $this->recordImport($drawId, $provider, $payload, $actor, $ingestion['fingerprint'] ?? null);

        $this->recordAudit($drawId, $this->sourceLabel($provider, $payload), $import, $ingestion, $fingerprint, $actor);

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

        // A ladder that could not use any source says WHY in provider_status.
        // "No credentials in this deployment" is a configuration fact, not an
        // outage, and the provenance row must not conflate them.
        if ($status === 'failed' && ($payload['provider_status'] ?? null) === 'not_configured') {
            $status = 'not_configured';
        }

        $mode = (string) config('glo.official_source.mode', 'fixture');

        return GloResultImport::query()->create([
            'draw_id' => $drawId,
            'provider' => $this->sourceLabel($provider, $payload),
            'mode' => $mode,
            'status' => $status,
            'endpoint' => isset($payload['endpoint']) ? (string) $payload['endpoint'] : null,
            'upstream_draw_id' => isset($payload['draw_number']) && (string) $payload['draw_number'] !== ''
                ? mb_substr((string) $payload['draw_number'], 0, 64)
                : null,
            // The PROVIDER fingerprint: it is the value an auditor can recompute
            // from the source payload, and the value this service compares when
            // deciding whether a replay is the same result. The ingestion
            // fingerprint (a hash over the canonicalized draw_result) belongs to
            // draw_results, where it is written, and is kept in the summary here
            // only as a cross-reference between the two rows.
            'result_fingerprint' => isset($payload['fingerprint']) && (string) $payload['fingerprint'] !== ''
                ? (string) $payload['fingerprint']
                : $normalizedFingerprint,
            'failure_reason' => isset($payload['failure_reason'])
                ? mb_substr((string) $payload['failure_reason'], 0, 500)
                : null,
            'import_reference' => 'glo-'.Str::lower(Str::ulid()->toBase32()),
            'imported_by' => $actor?->getKey(),
            'imported_at' => now(),
            'payload_summary' => $this->payloadSummary($payload, $normalizedFingerprint),
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
        string $sourceLabel,
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
                'provider' => $sourceLabel,
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
    /**
     * A bounded digest of what arrived - never the upstream document.
     *
     * The column is called `payload_summary` for a reason: an auditor needs the
     * draw number, the prize actually received and how many entries each tier
     * carried, not a copy of the response body. Anything that could carry a
     * credential is excluded by construction, because this method lists the keys
     * it keeps rather than filtering the ones it drops.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function payloadSummary(array $payload, ?string $ingestionFingerprint = null): array
    {
        $first = $payload['first_prize'] ?? null;
        $drawNumber = $payload['draw_number'] ?? null;

        return [
            'provider' => isset($payload['provider']) && is_string($payload['provider'])
                ? mb_substr($payload['provider'], 0, 32)
                : null,
            'draw_number' => is_string($drawNumber) ? mb_substr($drawNumber, 0, 64) : null,
            // Digit strings, never ints: leading zeros are part of a result.
            'first_prize' => is_string($first) ? $first : null,
            'second_prize_count' => is_array($payload['second_prize'] ?? null)
                ? count($payload['second_prize'])
                : 0,
            'third_prize_count' => is_array($payload['third_prize'] ?? null)
                ? count($payload['third_prize'])
                : 0,
            'has_n3' => is_array($payload['n3'] ?? null) && $payload['n3'] !== [],
            'tier_keys' => is_array($payload['tiers'] ?? null)
                ? array_slice(array_map('strval', array_keys($payload['tiers'])), 0, 20)
                : [],
            'fingerprint' => isset($payload['fingerprint']) && is_string($payload['fingerprint'])
                ? $payload['fingerprint']
                : null,
            'ingestion_fingerprint' => $ingestionFingerprint,
        ];
    }

    /**
     * Strips credential-shaped keys from a structure before it is logged.
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
