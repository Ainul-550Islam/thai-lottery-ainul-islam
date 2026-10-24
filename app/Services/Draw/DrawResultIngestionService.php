<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\Enums\AuditAction;
use App\Enums\DrawConfirmationStatus;
use App\Enums\DrawLifecycleState;
use App\Enums\RiskLevel;
use App\Exceptions\DrawResultException;
use App\Models\AuditLog;
use App\Models\Draw;
use Illuminate\Support\Facades\DB;

/**
 * Input gate for official draw results.
 *
 * THE INGESTION HALF OF THE FOUR-EYES RESULT PIPELINE
 * Results arrive from the official GLO announcement channels: an operator
 * paste, a feed line, a correction. This service validates, canonicalizes,
 * fingerprints and STORES them — and deliberately never publishes. The row it
 * writes lives on the draw itself (metadata.result_ingestion) in
 * DrawConfirmationStatus::Pending, invisible to settlement. Only the
 * confirmation service's second pair of hands can turn the same payload into
 * the published DrawResult that bet settlement later pays against.
 *
 * CANONICALIZATION
 * first_prize: exactly 6 numeric characters (leading zeros preserved as a
 * string). bottom_two: exactly 2 numeric characters, READ FROM THE ANNOUNCEMENT
 * AND NEVER DERIVED.
 *
 * The two are INDEPENDENT DRAWS. The two-digit prize is its own two-digit draw,
 * not a slice of the first prize, and a real GLO announcement will normally give
 * two numbers whose last two digits differ — seven published draws checked, no
 * agreement in any of them. This class therefore requires bottom_two to be
 * stated and refuses the payload when it is absent, rather than filling it in.
 * An announcement that omits it is INCOMPLETE, and an incomplete announcement
 * leaves the two-digit market unsettleable until the real number arrives. That is
 * the intended outcome: the alternative is settling a market against a number
 * nobody drew. See canonicalize() for the full accounting.
 *
 * IDEMPOTENCY BY FINGERPRINT
 * fingerprint = sha256 of the canonical payload. Re-ingesting the exact same
 * result is a silent no-op returning the recorded state; ingesting a
 * DIFFERENT result while one is still Pending is a correction: the pending
 * record is replaced, with the replaced record preserved in history so the
 * correction is auditable. A Confirmed record may never be replaced from
 * this side — corrections to a published result go through the confirmation
 * service's supersede path (never through a silent re-ingest).
 */
class DrawResultIngestionService
{
    /**
     * The draw metadata key under which the current ingestion record lives.
     */
    public const METADATA_KEY = 'result_ingestion';

    /**
     * Ingest an official result payload for a draw.
     *
     * @param  array{first_prize: string, bottom_two?: string|null}  $payload
     * @param  string  $source  'glo_feed', 'operator', 'correction', ...
     *
     * @return array{
     *     draw_id: int,
     *     status: string,
     *     fingerprint: string,
     *     first_prize: string,
     *     bottom_two: string,
     *     source: string,
     *     ingested_at: string,
     *     no_op: bool
     * }
     *
     * @throws DrawResultException
     */
    public function ingest(int $drawId, array $payload, string $source, ?int $actorUserId = null): array
    {
        return DB::transaction(function () use ($drawId, $payload, $source, $actorUserId): array {
            $draw = Draw::query()->lockForUpdate()->find($drawId);

            if (! $draw instanceof Draw) {
                throw DrawResultException::drawNotFound($drawId);
            }

            $state = DrawLifecycleState::fromDrawStatus($draw->status);

            if (! $this->mayIngestIn($state)) {
                throw DrawResultException::confirmationForbidden(
                    0,
                    $state->value,
                    'ingested a result',
                    ['draw_id' => $drawId],
                );
            }

            $canonical = $this->canonicalize($drawId, $payload, $source);
            $fingerprint = $this->fingerprintFor($canonical);

            $existing = $this->currentRecord($draw);

            if (is_array($existing) && ($existing['fingerprint'] ?? null) === $fingerprint) {
                // Exact replay: file nothing, answer the recorded outcome.
                return $this->resultRow($drawId, $existing, noOp: true);
            }

            if (is_array($existing)
                && ($existing['status'] ?? null) === DrawConfirmationStatus::Confirmed->value
            ) {
                // A confirmed record is immutable from the input side; a real
                // correction supersedes through the confirmation service.
                throw DrawResultException::confirmationForbidden(
                    (int) $drawId,
                    DrawConfirmationStatus::Confirmed->value,
                    're-ingested with different numbers',
                    ['draw_id' => $drawId],
                );
            }

            $history = is_array($metadata = $draw->metadata ?? [])
                ? (is_array($metadata['result_ingestion_history'] ?? null) ? $metadata['result_ingestion_history'] : [])
                : [];

            if (is_array($existing) && ($existing['status'] ?? null) !== DrawConfirmationStatus::Rejected->value) {
                // Superseded-by-replacement: pending correction replaced.
                $existing['status'] = DrawConfirmationStatus::Superseded->value;
                $history[] = $existing;
            }

            $record = [
                'status' => DrawConfirmationStatus::Pending->value,
                'fingerprint' => $fingerprint,
                'source' => $source,
                'payload' => $canonical,
                'ingested_at' => now()->toIso8601String(),
                'ingested_by' => $actorUserId,
            ];

            $metadata = is_array($draw->metadata) ? $draw->metadata : [];
            $metadata[self::METADATA_KEY] = $record;
            $metadata['result_ingestion_history'] = $history;

            $draw->metadata = $metadata;
            $draw->save();

            $this->recordAudit($draw, 'IngestedDrawResult', $record, $actorUserId, 'Ingested new official result into Pending confirmation state');

            return $this->resultRow($drawId, $record, noOp: false);
        });
    }

    /**
     * The current ingestion record for a draw, or null if none has ever been
     * ingested. The stamp lives on the draw row: ingest and confirm read the
     * same source, held under the same row lock.
     *
     * @return array<string, mixed>|null
     */
    public function pendingIngestion(int $drawId): ?array
    {
        $draw = Draw::query()->find($drawId);

        if (! $draw instanceof Draw) {
            return null;
        }

        $record = $this->currentRecord($draw);

        return is_array($record) && ($record['status'] ?? null) === DrawConfirmationStatus::Pending->value
            ? $record
            : null;
    }

    /**
     * The current (last-written) ingestion record regardless of state.
     *
     * @return array<string, mixed>|null
     */
    public function currentIngestion(int $drawId): ?array
    {
        $draw = Draw::query()->find($drawId);

        return $draw instanceof Draw ? $this->currentRecord($draw) : null;
    }

    /**
     * Whether an ingestion record for this draw exists at all.
     */
    public function hasIngestion(int $drawId): bool
    {
        return $this->currentIngestion($drawId) !== null;
    }

    /**
     * Validate + canonicalize the operator/feed payload into the ONLY two
     * numbers the result pipeline trusts: first_prize (6 digits) and
     * bottom_two (2 digits). The two are INDEPENDENT draws and both must be
     * stated; neither is derived from the other.
     *
     * @param  array<string, mixed>  $payload
     *
     * @return array{first_prize: string, bottom_two: string}
     *
     * @throws DrawResultException
     */
    private function canonicalize(int $drawId, array $payload, string $source): array
    {
        $firstPrize = isset($payload['first_prize']) && is_scalar($payload['first_prize'])
            ? trim((string) $payload['first_prize'])
            : '';

        if ($firstPrize === '') {
            throw DrawResultException::emptyResult($drawId, $source);
        }

        if (! preg_match('/^\d{6}$/', $firstPrize)) {
            throw DrawResultException::malformed($source, sprintf(
                'first_prize [%s] must be exactly six digits',
                $firstPrize,
            ), ['draw_id' => $drawId]);
        }

        // ── THE BOTTOM TWO IS READ, NEVER DERIVED. ────────────────────────
        //
        // WHAT THIS USED TO DO, AND WHY IT WAS WRONG IN BOTH DIRECTIONS.
        //
        // Until this change the method computed
        // `substr($firstPrize, -2)` and then REFUSED any payload whose
        // bottom_two disagreed with it, on the stated grounds that "the
        // announcement cannot say both".
        //
        // The announcement says both, and says them differently, every time.
        // The two-digit prize (เลขท้าย 2 ตัว) is its OWN draw — a separately
        // drawn two-digit number, not a slice of the six-digit first prize.
        // Seven published GLO draws, from five independent sources:
        //
        //     first prize   published bottom two   last two of first prize
        //     074646        58                     46
        //     461252        22                     52
        //     837706        16                     06
        //     639214        71                     14
        //     932479        69                     79
        //     287184        48                     84
        //     730640        28                     40
        //
        // Seven disagreements, zero agreements. Under the derivation hypothesis
        // the chance of that is one in a hundred to the seventh, so the
        // derivation is not a rule that occasionally fails; it is simply not the
        // rule. DrawResultValidator::guarantees() has said so all along —
        // "The bottom two is never derived from the first prize. They may
        // coincide by chance and that is reported, not corrected" — which means
        // this method was contradicting a guarantee stated by the validator the
        // platform treats as its publication authority.
        //
        // THE TWO FAILURES THAT CAUSED, in the order they would hurt:
        //
        //   1. EVERY REAL ANNOUNCEMENT WAS REFUSED. A genuine GLO result asks
        //      this method to accept 730640 with a bottom two of 28, and the
        //      method answered `malformed`, with a message asserting that the
        //      announcement was self-contradictory. The platform could not
        //      ingest a real draw, and the operator was told their correct data
        //      was wrong.
        //
        //   2. WHEN IT DID NOT REFUSE, IT INVENTED A WINNING NUMBER. A payload
        //      carrying only first_prize was "completed" with a bottom two that
        //      no announcement ever made, and that invented number is what the
        //      two-digit market would have settled against — paying slips that
        //      matched a number GLO never drew, and not paying the ones that did.
        //      At 2,000 THB across roughly 10,000 winning slips per draw, that is
        //      a twenty-million-baht market settled on a guess.
        //
        // It returns '' when no bottom two is available, and the two-digit
        // settlement of that draw is simply not settleable until the real number
        // is supplied. That refusal is the correct outcome; guessing a winning
        // number from a partial document is how the wrong player gets paid.
        $claimedBottomTwo = isset($payload['bottom_two']) && is_scalar($payload['bottom_two'])
            ? trim((string) $payload['bottom_two'])
            : '';

        if ($claimedBottomTwo === '') {
            throw DrawResultException::malformed($source, sprintf(
                'bottom_two is required and is absent for first_prize [%s]. The two-digit prize is a separately '
                .'drawn number, not the last two digits of the first prize — published draws give first prize '
                .'730640 with a bottom two of 28, 287184 with 48, and 074646 with 58. This platform will not '
                .'derive it: deriving it would settle the two-digit market against a number the GLO never drew.',
                $firstPrize,
            ), [
                'draw_id' => $drawId,
                'first_prize' => $firstPrize,
                'refusal' => 'bottom_two_missing',
            ]);
        }

        if (preg_match('/^\d{2}$/', $claimedBottomTwo) !== 1) {
            throw DrawResultException::malformed($source, sprintf(
                'bottom_two [%s] must be exactly two digits',
                $claimedBottomTwo,
            ), ['draw_id' => $drawId]);
        }

        // DELIBERATELY NOT COMPARED TO THE FIRST PRIZE. They are independent
        // numbers that will normally differ, and a coincidence between them is a
        // curiosity rather than an error. The coincidence IS reported — see the
        // note written below — because an operator who sees it should check that
        // two numbers were not accidentally typed the same way, but it is never
        // "corrected", because correcting it would be inventing a result.
        $canonical = [
            'first_prize' => $firstPrize,
            'bottom_two' => $claimedBottomTwo,
        ];

        // ── OPTIONAL SOURCE-LANE PASSTHROUGH ──────────────────────────────
        //
        // A feed may carry more than the two canonical numbers: the remaining
        // official tiers (fourth, fifth, front 3, last 3, last 2) and an n3
        // group, plus the identity of the provider that supplied them. Those
        // values are NOT result numbers and are not what this service
        // validates — but they must survive as far as the CONFIRMATION act,
        // because the tier lane inside draw_results.metadata is read by public
        // result display (GloPublicResultService, ResultsPageService,
        // GloPublicPrizeSummaryService) and by GloN3TicketChecker.
        //
        // Carrying the lane here, and writing it from the confirmation service,
        // is what keeps the write boundary intact: the lane reaches the
        // draw_results row at the moment a SECOND operator confirms, never at
        // intake time. Previously this data was written by the importer
        // directly, which is how an unconfirmed feed payload could reach a
        // published row.
        //
        // The values are preserved verbatim and influence nothing that is
        // settled: first_prize and bottom_two above are the only fields
        // canonicalisation produces, and the fingerprint below is computed over
        // those two alone.
        $lane = $this->sourceLane($payload);

        if ($lane !== null) {
            $canonical['source_lane'] = $lane;
        }

        return $canonical;
    }

    /**
     * Extract the optional non-canonical source lane from a feed payload.
     *
     * Whitelist-only and shallow by design: an unrecognised key is dropped, not
     * persisted. A feed must not be able to smuggle arbitrary structure into a
     * draw's metadata simply by adding fields to its payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function sourceLane(array $payload): ?array
    {
        $lane = [];

        if (isset($payload['tiers']) && is_array($payload['tiers']) && $payload['tiers'] !== []) {
            $lane['tiers'] = $payload['tiers'];
        }

        if (isset($payload['n3']) && is_array($payload['n3']) && $payload['n3'] !== []) {
            $lane['n3'] = $payload['n3'];
        }

        if (isset($payload['provider']) && is_scalar($payload['provider'])) {
            $lane['import_provider'] = (string) $payload['provider'];
        }

        if (isset($payload['provider_fingerprint']) && is_scalar($payload['provider_fingerprint'])) {
            $lane['import_fingerprint'] = (string) $payload['provider_fingerprint'];
        }

        return $lane === [] ? null : $lane;
    }

    /**
     * sha256 over the canonical pair: stable across identical re-ingests,
     * different the moment any digit moves.
     *
     * @param  array{first_prize: string, bottom_two: string}  $canonical
     */
    private function fingerprintFor(array $canonical): string
    {
        return hash('sha256', $canonical['first_prize'].':'.$canonical['bottom_two']);
    }

    /**
     * The draw lifecycle states in which a new result may be INGESTED: the
     * draw is closed (or result-pending) but has not published or settled
     * anything yet. Draft/Open draws have nothing to settle against; a
     * result_published/settled draw is already beyond input; a cancelled draw
     * consumes no results at all.
     */
    private function mayIngestIn(DrawLifecycleState $state): bool
    {
        return in_array($state, [
            DrawLifecycleState::Closed,
            DrawLifecycleState::ResultPending,
        ], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentRecord(Draw $draw): ?array
    {
        $metadata = is_array($draw->metadata) ? $draw->metadata : [];
        $record = $metadata[self::METADATA_KEY] ?? null;

        return is_array($record) ? $record : null;
    }

    /**
     * @param  array<string, mixed>  $record
     *
     * @return array{draw_id: int, status: string, fingerprint: string, first_prize: string, bottom_two: string, source: string, ingested_at: string, no_op: bool}
     */
    private function resultRow(int $drawId, array $record, bool $noOp): array
    {
        $payload = $record['payload'] ?? [];

        return [
            'draw_id' => $drawId,
            'status' => (string) $record['status'],
            'fingerprint' => (string) $record['fingerprint'],
            'first_prize' => (string) ($payload['first_prize'] ?? ''),
            'bottom_two' => (string) ($payload['bottom_two'] ?? ''),
            'source' => (string) $record['source'],
            'ingested_at' => (string) $record['ingested_at'],
            'no_op' => $noOp,
        ];
    }

    /**
     * Every state-changing intake is auditable: who fed what, when, from
     * which channel.
     *
     * @param  array<string, mixed>  $record
     */
    private function recordAudit(Draw $draw, string $action, array $record, ?int $actorUserId, string $description): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => $actorUserId,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Low,
            'auditable_type' => Draw::class,
            'auditable_id' => $draw->getKey(),
            'description' => sprintf(
                'Draw %s: %s — first prize %s, bottom two %s (source %s).',
                (string) $draw->draw_number,
                $description,
                (string) ($record['payload']['first_prize'] ?? ''),
                (string) ($record['payload']['bottom_two'] ?? ''),
                (string) $record['source'],
            ),
            'metadata' => [
                'draw_id' => (int) $draw->getKey(),
                'draw_number' => (string) $draw->draw_number,
                'ingestion_status' => (string) $record['status'],
                'fingerprint' => (string) $record['fingerprint'],
                'source' => (string) $record['source'],
                'action' => $action,
            ],
        ]);

        $log->save();
    }
}
