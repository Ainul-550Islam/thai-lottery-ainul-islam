<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\Enums\AuditAction;
use App\Enums\DrawConfirmationStatus;
use App\Enums\RiskLevel;
use App\Exceptions\DrawLifecycleException;
use App\Exceptions\DrawResultException;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\DrawResult;
use Illuminate\Support\Facades\DB;

/**
 * The SECOND pair of eyes over an ingested draw result.
 *
 * FLOW
 * ----
 * DrawResultIngestionService has stored a Pending record (the official result
 * as one hand entered it). This service is the second hand:
 *
 *   confirm($drawId, $operatorId, $claimed):
 *     1. Re-read the Pending ingestion under the draw row lock.
 *     2. Compare every claimed number with what ingestion stored. A claimed
 *        value that disagrees with the stored one is reported as a mismatch
 *        and the confirmation is refused — confirming either version would
 *        attest a falsehood.
 *     3. Publish through DrawResultPublicationService::publish() — the same
 *        validated, transactional path any other publication takes, which
 *        writes draw_results + winning_numbers and moves the lifecycle to
 *        result_published atomically.
 *     4. Write the confirmation stamp (status Confirmed, confirmed_by/at,
 *        linked draw_result id) onto the same draw metadata record, and
 *        supersede any previously Confirmed sibling in history.
 *
 *   reject($drawId, $operatorId, $reason):
 *     Marks the Pending record Rejected with the operator's reason; the draw
 *     remains ingestable so a corrected result can come in through the
 *     normal intake, and the rejection is kept in history.
 *
 * INVARIANTS
 * - Only a Confirmed record is ever published — this class is the ONLY door
 *   from the four-eyes pipeline into publication, and it publishes nothing
 *   above the Pending it confirmed.
 * - Confirmation and rejection are both one-shot: a Confirmed record can only
 *   be superseded, never un-confirmed, and a Rejected record is terminal.
 * - All writes live inside one transaction; a publication that throws leaves
 *   the ingestion record Pending and the draw untouched.
 */
class DrawResultConfirmationService
{
    public function __construct(
        private readonly DrawResultIngestionService $ingestion,
        private readonly DrawResultPublicationService $publication,
    ) {
    }

    /**
     * Confirm the pending ingestion by comparing claimed numbers against it
     * and publishing the stored result.
     *
     * @param  array{first_prize: string, bottom_two?: string|null}  $claimed
     *
     * @return array{
     *     draw_id: int,
     *     status: string,
     *     draw_result_id: int,
     *     confirmed_by: int,
     *     confirmed_at: string,
     *     published: bool
     * }
     *
     * @throws DrawResultException
     * @throws DrawLifecycleException
     */
    public function confirm(int $drawId, int $operatorId, array $claimed): array
    {
        return DB::transaction(function () use ($drawId, $operatorId, $claimed): array {
            $draw = $this->lockedDraw($drawId);
            $record = $this->pendingRecord($draw, throwNotPending: true);

            // ── FOUR-EYES: THE CONFIRMING OPERATOR MUST NOT BE THE INGESTING ONE.
            //
            // This is the entire control. Without it the "second pair of eyes"
            // is a second click by the same person, and the separation between
            // maker and checker exists only in the documentation.
            //
            // The record's own `ingested_by` is the attribution — written by
            // DrawResultIngestionService::ingest() from the authenticated actor,
            // never from request input. Three ways this is refused:
            //
            //   1. ingested_by is absent  -> the record cannot be attributed to
            //      anybody, so it cannot be reviewed by a different person. This
            //      is what makes the control fail CLOSED: a legacy or hand-edited
            //      record with no attributable ingester is not silently
            //      confirmable by whoever happens to be standing there.
            //   2. ingested_by < 1        -> an unattributed ingest (e.g. a feed
            //      with no operator), same refusal.
            //   3. ingested_by === operator -> the same person in both seats.
            //
            // This block is also present in P0-GLO-WRITE-BOUNDARY.patch, which
            // was applied in commit 544d319 and then reverted by the restore
            // commit f5cec15. It is restored here and gated by a CI assertion so
            // that a future restore cannot silently remove it again.
            $ingestedBy = isset($record['ingested_by']) ? (int) $record['ingested_by'] : null;

            if ($ingestedBy === null || $ingestedBy < 1 || $ingestedBy === $operatorId) {
                throw DrawResultException::confirmationForbidden(
                    $drawId,
                    DrawConfirmationStatus::Pending->value,
                    $ingestedBy === $operatorId
                        ? 'confirmed by the same operator who ingested it'
                        : 'confirmed without an attributable ingesting operator',
                    ['draw_id' => $drawId, 'operator_id' => $operatorId],
                );
            }

            $stored = $record['payload'] ?? [];
            $claimedNormalized = $this->normalizeClaim($drawId, $claimed);

            $this->assertClaimsAgree($draw->getKey(), $stored, $claimedNormalized);

            // Publish via the one true publication path.
            //
            // The source lane (optional tier/n3/provider structure carried by a
            // feed payload) is passed through here and written only now —
            // AFTER the second operator has attested the canonical numbers.
            // This is the point the write boundary was designed around: the
            // importer never writes a draw_results row, so the tier lane cannot
            // reach settlement or public display without two operators.
            $sourceLane = is_array($stored['source_lane'] ?? null) ? $stored['source_lane'] : [];

            $published = $this->publication->publish(
                $drawId,
                [
                    'first_prize' => (string) $stored['first_prize'],
                    'bottom_two' => (string) $stored['bottom_two'],
                ],
            );

            $drawResultId = isset($published['result']) && $published['result'] instanceof \App\Models\DrawResult
                ? (int) $published['result']->getKey()
                : 0;

            // ── SOURCE LANE: written now, by the CONFIRMING act.
            //
            // The tier/n3/provider structure a feed carried is merged into the
            // published row's metadata HERE, not at intake. Two reasons this is
            // the correct place and the correct writer:
            //
            //   1. It is the only moment at which a second operator has attested
            //      the canonical numbers. Everything downstream that reads this
            //      lane — public tier display, GloN3TicketChecker — is therefore
            //      reading data a human verified.
            //   2. The importer is structurally incapable of reaching this row.
            //      That is the write boundary: an unattended feed cannot publish,
            //      and cannot even prepare a publishable lane.
            //
            // A merge, not a replacement: publication has already written
            // `bottom_two`, `first_prize_digits` and `published_by_phase`, and
            // dropping those would break MarketResultResolver.
            $this->writeSourceLane($published['result'] ?? null, $sourceLane, $operatorId);

            $stamp = $record;
            $stamp['status'] = DrawConfirmationStatus::Confirmed->value;
            $stamp['confirmed_by'] = $operatorId;
            $stamp['confirmed_at'] = now()->toIso8601String();
            $stamp['draw_result_id'] = $drawResultId;

            $this->writeRecord($draw, $stamp, supersedeConfirmedSiblings: true);

            $this->recordAudit($draw, $stamp, $operatorId, sprintf(
                'Confirmed and published official result (first prize %s, bottom two %s).',
                (string) $stored['first_prize'],
                (string) $stored['bottom_two'],
            ));

            return [
                'draw_id' => $drawId,
                'status' => DrawConfirmationStatus::Confirmed->value,
                'draw_result_id' => $drawResultId,
                'confirmed_by' => $operatorId,
                'confirmed_at' => $stamp['confirmed_at'],
                'published' => true,
            ];
        });
    }

    /**
     * Reject the pending ingestion with an operator reason. The draw stays
     * ingestable: a correction can be pasted through the normal intake.
     *
     * @return array{draw_id: int, status: string, rejected_by: int, rejected_at: string, reason: string}
     *
     * @throws DrawResultException
     */
    public function reject(int $drawId, int $operatorId, string $reason): array
    {
        return DB::transaction(function () use ($drawId, $operatorId, $reason): array {
            $draw = $this->lockedDraw($drawId);
            $record = $this->pendingRecord($draw, throwNotPending: true);

            $stamp = $record;
            $stamp['status'] = DrawConfirmationStatus::Rejected->value;
            $stamp['rejected_by'] = $operatorId;
            $stamp['rejected_at'] = now()->toIso8601String();
            $stamp['rejection_reason'] = $reason;

            // A rejected record moves to history and clears the current slot,
            // so the next ingestion starts with a clean Pending plaque.
            $this->relegiteRecord($draw, $stamp);

            $this->recordAudit($draw, $stamp, $operatorId, sprintf(
                'REJECTED ingested official result: %s.',
                $reason,
            ));

            return [
                'draw_id' => $drawId,
                'status' => DrawConfirmationStatus::Rejected->value,
                'rejected_by' => $operatorId,
                'rejected_at' => $stamp['rejected_at'],
                'reason' => $reason,
            ];
        });
    }

    /**
     * The current confirmation state of a draw's ingestion, or null when no
     * ingestion record exists.
     */
    public function statusOf(int $drawId): ?DrawConfirmationStatus
    {
        $record = $this->ingestion->currentIngestion($drawId);

        if ($record === null || ! isset($record['status']) || ! is_string($record['status'])) {
            return null;
        }

        return DrawConfirmationStatus::tryFrom($record['status']);
    }

    /**
     * What the operator would be confirming, for the review screen:
     * first prize, bottom two, source and the fingerprint that binds them.
     *
     * @return array{draw_id: int, first_prize: string, bottom_two: string, source: string, fingerprint: string, ingested_at: string}|null
     */
    public function previewFor(int $drawId): ?array
    {
        $pending = $this->ingestion->pendingIngestion($drawId);

        if ($pending === null) {
            return null;
        }

        $payload = $pending['payload'] ?? [];

        return [
            'draw_id' => $drawId,
            'first_prize' => (string) ($payload['first_prize'] ?? ''),
            'bottom_two' => (string) ($payload['bottom_two'] ?? ''),
            'source' => (string) ($pending['source'] ?? ''),
            'fingerprint' => (string) ($pending['fingerprint'] ?? ''),
            'ingested_at' => (string) ($pending['ingested_at'] ?? ''),
        ];
    }

    /**
     * @throws DrawResultException
     */
    private function lockedDraw(int $drawId): Draw
    {
        $draw = Draw::query()->lockForUpdate()->find($drawId);

        if (! $draw instanceof Draw) {
            throw DrawResultException::drawNotFound($drawId);
        }

        return $draw;
    }

    /**
     * The Pending record of a locked draw, hard-failing when there is none (a
     * missing Pending means there was nothing to review).
     *
     * @return array<string, mixed>
     *
     * @throws DrawResultException
     */
    private function pendingRecord(Draw $draw, bool $throwNotPending): array
    {
        $drawId = (int) $draw->getKey();
        $metadata = is_array($draw->metadata) ? $draw->metadata : [];
        $record = $metadata[DrawResultIngestionService::METADATA_KEY] ?? null;

        if (! is_array($record)) {
            throw DrawResultException::confirmationForbidden(
                $drawId,
                'nothing-ingested',
                'reviewed',
                ['draw_id' => $drawId],
            );
        }

        $status = is_string($record['status'] ?? null) ? $record['status'] : '';

        if ($status !== DrawConfirmationStatus::Pending->value && $throwNotPending) {
            throw DrawResultException::confirmationForbidden(
                $drawId,
                $status === '' ? 'unknown' : $status,
                'reviewed again',
                ['draw_id' => $drawId],
            );
        }

        return $record;
    }

    /**
     * The operator's claimed numbers in the same canonical shape ingestion
     * stored.
     *
     * ── AN ABSENT bottom_two IS REFUSED, NOT DERIVED. ─────────────────────
     *
     * This used to fall back to `substr($firstPrize, -2)`, and the fallback
     * quietly broke the control this entire class exists to enforce.
     *
     * The second operator's job is to attest the numbers. If they state the
     * first prize and omit the bottom two, the old code filled the gap from the
     * first prize and then compared it to the stored value — so the comparison
     * passed, and the confirmation record says the checker attested a two-digit
     * number they never typed, against a stored value that had itself been
     * invented the same way. Two systems agreeing on a number neither was told
     * is not verification; it is a tautology with a signature on it.
     *
     * And the number was wrong. The bottom two is a separately drawn number, not
     * a slice of the first prize — see DrawResultIngestionService::canonicalize()
     * for the seven published draws. The effect of the fallback was that a
     * checker who never looked at the two-digit prize came away from the screen
     * having "confirmed" whichever number the derivation produced.
     *
     * So the omission is now a refusal. A checker must state both numbers they
     * are attesting, because the record of their attestation is evidence.
     *
     * @param  array<string, mixed>  $claimed
     *
     * @return array{first_prize: string, bottom_two: string}
     *
     * @throws DrawResultException
     */
    private function normalizeClaim(int $drawId, array $claimed): array
    {
        $firstPrize = isset($claimed['first_prize']) && is_scalar($claimed['first_prize'])
            ? trim((string) $claimed['first_prize'])
            : '';

        $bottomTwo = isset($claimed['bottom_two']) && is_scalar($claimed['bottom_two'])
            ? trim((string) $claimed['bottom_two'])
            : '';

        if ($bottomTwo === '') {
            throw DrawResultException::malformed('confirmation-claim', sprintf(
                'the confirming operator did not state bottom_two for draw %d. Confirmation is an attestation of '
                .'BOTH numbers: the two-digit prize is a separately drawn number, so this platform will neither '
                .'derive it from first_prize [%s] nor accept a confirmation in which it was never stated. State the '
                .'announced bottom two and confirm again.',
                $drawId,
                $firstPrize === '' ? '(absent)' : $firstPrize,
            ), [
                'draw_id' => $drawId,
                'refusal' => 'bottom_two_not_attested',
            ]);
        }

        return [
            'first_prize' => $firstPrize,
            'bottom_two' => $bottomTwo,
        ];
    }

    /**
     * @param  array{first_prize?: string, bottom_two?: string}  $stored
     * @param  array{first_prize: string, bottom_two: string}  $claimed
     *
     * @throws DrawResultException
     */
    private function assertClaimsAgree(int $drawId, array $stored, array $claimed): void
    {
        if (($stored['first_prize'] ?? '') !== $claimed['first_prize']) {
            throw DrawResultException::mismatchConfirmed(
                $drawId,
                'first_prize',
                (string) ($stored['first_prize'] ?? ''),
                $claimed['first_prize'],
                ['draw_id' => $drawId],
            );
        }

        if (($stored['bottom_two'] ?? '') !== $claimed['bottom_two']) {
            throw DrawResultException::mismatchConfirmed(
                $drawId,
                'bottom_two',
                (string) ($stored['bottom_two'] ?? ''),
                $claimed['bottom_two'],
                ['draw_id' => $drawId],
            );
        }
    }

    /**
     * Merge the optional source lane into a just-published DrawResult.
     *
     * WHAT THIS PRESERVES
     * The tier key that every downstream reader resolves through
     * config('glo.tiers.metadata_key'), matching the shape the previous
     * importer-written lane had:
     *
     *     metadata[$tierKey] = [
     *         ...tier lists from the feed...,   // fourth/fifth/front3/last3/last2
     *         'n3'                => [...],     // present only when the feed had it
     *         'import_provider'   => 'official' | 'fixture',
     *         'import_fingerprint'=> <provider fingerprint>,
     *         'imported_at'       => ISO8601,
     *     ]
     *
     * GloPublicResultService::sourceStateFor() reads `import_provider` and
     * compares it against the literal strings 'official' and 'fixture' — those
     * are echoed verbatim from the provider's own name() and are not altered
     * here, because altering them would silently downgrade an official source to
     * "internal reconciled" on every public result page.
     *
     * NON-MONETARY AND NON-AUTHORITATIVE
     * Nothing in this lane is read when settlement decides what a bet won:
     * first_prize and bottom_two live in dedicated columns, and n3 settlement
     * (GloN3SettlementService) writes its own keys into the same lane under its
     * own fingerprint. This method therefore merges rather than overwrites, so
     * it cannot clobber an n3 settlement that has already run.
     *
     * @param  mixed  $result  the DrawResult returned by publication, if any
     * @param  array<string, mixed>  $sourceLane
     */
    private function writeSourceLane(mixed $result, array $sourceLane, int $operatorId): void
    {
        if (! $result instanceof DrawResult) {
            // Nothing was published (a publication path that answered without a
            // row). Refuse to invent one: this method writes to an existing row.
            return;
        }

        if ($sourceLane === []) {
            // A plain operator paste carries no lane. Leave publication's own
            // metadata exactly as it is.
            return;
        }

        $tierKey = (string) config('glo.tiers.metadata_key', 'glo');

        $metadata = is_array($result->metadata) ? $result->metadata : [];
        $lane = is_array($metadata[$tierKey] ?? null) ? $metadata[$tierKey] : [];

        $tiers = $sourceLane['tiers'] ?? [];
        if (is_array($tiers)) {
            $lane = array_merge($lane, $tiers);
        }

        if (isset($sourceLane['n3']) && is_array($sourceLane['n3'])) {
            $lane['n3'] = $sourceLane['n3'];
        }

        if (isset($sourceLane['import_provider'])) {
            $lane['import_provider'] = (string) $sourceLane['import_provider'];
        }

        if (isset($sourceLane['import_fingerprint'])) {
            $lane['import_fingerprint'] = (string) $sourceLane['import_fingerprint'];
        }

        $lane['imported_at'] = now()->toIso8601String();

        // Attribution: which SECOND operator let this lane onto the row. This is
        // the value an auditor reads to answer "who vouched for the tier list
        // the public is looking at".
        $lane['lane_confirmed_by'] = $operatorId;

        $metadata[$tierKey] = $lane;

        $result->metadata = $metadata;
        $result->save();
    }

    /**
     * @param  array<string, mixed>  $stamp
     */
    private function writeRecord(Draw $draw, array $stamp, bool $supersedeConfirmedSiblings): void
    {
        $metadata = is_array($draw->metadata) ? $draw->metadata : [];
        $history = is_array($metadata['result_ingestion_history'] ?? null)
            ? $metadata['result_ingestion_history']
            : [];

        if ($supersedeConfirmedSiblings) {
            foreach ($history as $i => $sibling) {
                if (is_array($sibling)
                    && ($sibling['status'] ?? null) === DrawConfirmationStatus::Confirmed->value
                ) {
                    $sibling['status'] = DrawConfirmationStatus::Superseded->value;
                    $history[$i] = $sibling;
                }
            }
        }

        $metadata[DrawResultIngestionService::METADATA_KEY] = $stamp;
        $metadata['result_ingestion_history'] = $history;

        $draw->metadata = $metadata;
        $draw->save();
    }

    /**
     * A rejected record leaves the CURRENT slot and joins history, so the
     * next intake sees a clean slate and the rejection stays auditable.
     *
     * @param  array<string, mixed>  $stamp
     */
    private function relegiteRecord(Draw $draw, array $stamp): void
    {
        $metadata = is_array($draw->metadata) ? $draw->metadata : [];
        $history = is_array($metadata['result_ingestion_history'] ?? null)
            ? $metadata['result_ingestion_history']
            : [];

        $history[] = $stamp;

        unset($metadata[DrawResultIngestionService::METADATA_KEY]);
        $metadata['result_ingestion_history'] = $history;

        $draw->metadata = $metadata;
        $draw->save();
    }

    /**
     * @param  array<string, mixed>  $stamp
     */
    private function recordAudit(Draw $draw, array $stamp, int $operatorId, string $description): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => $operatorId,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::High,
            'auditable_type' => Draw::class,
            'auditable_id' => $draw->getKey(),
            'description' => sprintf('Draw %s confirmation: %s', (string) $draw->draw_number, $description),
            'metadata' => [
                'draw_id' => (int) $draw->getKey(),
                'draw_number' => (string) $draw->draw_number,
                'confirmation_status' => (string) $stamp['status'],
                'fingerprint' => (string) ($stamp['fingerprint'] ?? ''),
                'draw_result_id' => $stamp['draw_result_id'] ?? null,
                'rejection_reason' => $stamp['rejection_reason'] ?? null,
            ],
        ]);

        $log->save();
    }
}
