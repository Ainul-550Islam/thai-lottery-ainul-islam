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
use App\Models\User;
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
 *     3. Stamp the ingestion Confirmed inside the outer transaction, then
 *        publish through DrawResultPublicationService::publish(). The writer
 *        re-reads the locked persisted maker/checker record and provenance,
 *        writes draw_results + winning_numbers and advances lifecycle atomically.
 *     4. Link the authoritative draw_result id, supersede any previously
 *        Confirmed sibling in history, and write the confirmation audit before
 *        the outer transaction commits.
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
     * @param  string|null  $confirmationNotes  Required at operator-facing API/CLI boundaries and stored on the audit row
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
    public function confirm(
        int $drawId,
        mixed $operatorId,
        array $claimed,
        ?string $confirmationNotes = null,
    ): array {
        $confirmationNotes = $confirmationNotes === null ? null : trim($confirmationNotes);

        if ($confirmationNotes !== null && (mb_strlen($confirmationNotes) < 5 || mb_strlen($confirmationNotes) > 500)) {
            throw DrawResultException::malformed(
                'confirmation-notes',
                'confirmation notes must be between 5 and 500 characters',
                ['draw_id' => $drawId],
            );
        }

        return DB::transaction(function () use ($drawId, $operatorId, $claimed, $confirmationNotes): array {
            $draw = $this->lockedDraw($drawId);
            $record = $this->pendingRecord($draw, throwNotPending: true);
            $checkerId = $this->persistedOperatorId($operatorId);

            if ($checkerId === null) {
                throw DrawResultException::confirmationForbidden(
                    $drawId,
                    DrawConfirmationStatus::Pending->value,
                    'confirmed without an attributable persisted checking operator',
                    ['draw_id' => $drawId, 'operator_id' => $checkerId],
                );
            }

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
            // Regression coverage in tests/Feature/Glo/GloWriteBoundaryTest.php
            // protects the maker/checker and unattributable-maker refusals.
            $ingestedBy = $this->persistedOperatorId($record['ingested_by'] ?? null);

            if ($ingestedBy === null || $ingestedBy === $checkerId) {
                throw DrawResultException::confirmationForbidden(
                    $drawId,
                    DrawConfirmationStatus::Pending->value,
                    $ingestedBy === $checkerId
                        ? 'confirmed by the same operator who ingested it'
                        : 'confirmed without an attributable ingesting operator',
                    [
                        'draw_id' => $drawId,
                        'operator_id' => $checkerId,
                        'ingested_by' => $ingestedBy,
                    ],
                );
            }

            $stored = $record['payload'] ?? [];
            $claimedNormalized = $this->normalizeClaim($drawId, $claimed);

            $this->assertClaimsAgree($draw->getKey(), $stored, $claimedNormalized);

            // The publisher independently requires a persisted Confirmed state.
            // Stamp that state inside this outer transaction before the call; no
            // other transaction can observe it until publication, winning-number
            // generation, lifecycle advancement and audit have all succeeded.
            // Any failure rolls this stamp back with the rest of the operation.
            $stamp = $record;
            $stamp['status'] = DrawConfirmationStatus::Confirmed->value;
            $stamp['confirmed_by'] = $checkerId;
            $stamp['confirmed_at'] = now()->toIso8601String();
            unset($stamp['draw_result_id']);

            $this->writeRecord($draw, $stamp, supersedeConfirmedSiblings: true);

            $publicationInput = [
                'first_prize' => (string) $stored['first_prize'],
                'bottom_two' => (string) $stored['bottom_two'],
            ];

            // Keep official second/third prize lists on the pending card until
            // this checker act. The publication validator re-validates them and
            // the sole writer persists their existing DrawResult columns; the
            // importer itself still cannot write those columns.
            foreach (['second_prize', 'third_prize'] as $optionalField) {
                if (array_key_exists($optionalField, $stored)) {
                    $publicationInput[$optionalField] = $stored[$optionalField];
                }
            }

            $published = $this->publication->publish($drawId, $publicationInput);

            $drawResultId = isset($published['result']) && $published['result'] instanceof \App\Models\DrawResult
                ? (int) $published['result']->getKey()
                : 0;

            if ($drawResultId < 1) {
                throw DrawResultException::publishRequiresConfirmed(
                    $drawId,
                    DrawConfirmationStatus::Confirmed->value,
                    ['stage' => 'publication_returned_no_result'],
                );
            }

            $stamp['draw_result_id'] = $drawResultId;
            $this->writeRecord($draw, $stamp, supersedeConfirmedSiblings: false);

            $this->recordAudit($draw, $stamp, $checkerId, sprintf(
                'Confirmed and published official result (first prize %s, bottom two %s).',
                (string) $stored['first_prize'],
                (string) $stored['bottom_two'],
            ), $confirmationNotes);

            return [
                'draw_id' => $drawId,
                'status' => DrawConfirmationStatus::Confirmed->value,
                'draw_result_id' => $drawResultId,
                'confirmed_by' => $checkerId,
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
     * Normalize an operator reference and require that it names a persisted user.
     *
     * Numeric strings are accepted for JSON/CLI compatibility; booleans, floats,
     * null, malformed strings, zero, overflow and unknown user ids fail closed.
     */
    private function persistedOperatorId(mixed $candidate): ?int
    {
        if (is_int($candidate)) {
            $id = $candidate > 0 ? $candidate : null;
        } elseif (is_string($candidate) && preg_match('/\A[0-9]+\z/D', $candidate) === 1) {
            $normalized = ltrim($candidate, '0');
            $parsed = $normalized === ''
                ? false
                : filter_var($normalized, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $id = is_int($parsed) ? $parsed : null;
        } else {
            $id = null;
        }

        if ($id === null || ! User::query()->whereKey($id)->exists()) {
            return null;
        }

        return $id;
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
    private function recordAudit(
        Draw $draw,
        array $stamp,
        int $operatorId,
        string $description,
        ?string $confirmationNotes = null,
    ): void
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
                'confirmation_notes' => $confirmationNotes === null
                    ? null
                    : $this->safeConfirmationNotes($confirmationNotes),
            ],
        ]);

        $log->save();
    }

    /**
     * Keep sensitive credentials out of free-text operator notes in the audit row.
     */
    private function safeConfirmationNotes(string $notes): string
    {
        $notes = preg_replace(
            '/\\bauthorization\\s*[:=]\\s*(?:bearer\\s+)?[^\\s,;]+/i',
            'authorization=[redacted]',
            $notes,
        ) ?? $notes;

        $notes = preg_replace(
            '/\\b(api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|secret|password|signature)\\s*[:=]\\s*[^\\s,;]+/i',
            '$1=[redacted]',
            $notes,
        ) ?? $notes;

        return mb_substr($notes, 0, 500);
    }
}
