<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\DTOs\DrawResultData;
use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\RiskLevel;
use App\Exceptions\SettlementRunMovedException;
use App\Exceptions\SettlementSimulationException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\SettlementRun;
use App\Models\User;
use App\Models\WinningNumber;
use App\Support\Admin\AdminAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Settles one published draw in resumable chunks of bets, with a durable run row.
 *
 * ============================================================================
 * THE SHAPE OF A RUN
 * ============================================================================
 *   start()    opens ONE settlement_runs row for the draw (or returns the one that
 *              exists). Refuses, before any row is written, if the draw is not
 *              settleable. Records chunk size, safety mode, participant count and
 *              the provenance fingerprints. Nothing about these changes afterwards.
 *   runChunk() settles the next slice of bets. The cursor is read from the DATABASE,
 *              never from the caller's object, so a stale or retried caller resumes
 *              from where the money actually stopped.
 *   finalize() moves the draw to Settled. It refuses while any bet remains past the
 *              cursor. Until then the draw stays ResultPublished, which together with
 *              an open run row is the unambiguous signature of "partly settled".
 *   abort()    records a failure reason and an audit entry, and stops the run. An
 *              aborted run is resumable, never silently restarted.
 *   recover() / resume()
 *              re-open an aborted or interrupted run. Both require a reason and a
 *              user holding the process-settlements permission, and both write the
 *              audit entry first.
 *
 * ============================================================================
 * WHY THE CURSOR AND THE MONEY MOVE TOGETHER
 * ============================================================================
 * Each chunk is one transaction. The payouts AND the cursor/totals update are written
 * inside it, through the checkpoint callback on settleBetRange(). A chunk either
 * happens completely or not at all. A crash between "paid" and "cursor advanced" is
 * therefore impossible, and the deterministic per-(draw, bet) payout reference
 * blocks a double payment on any retry regardless.
 *
 * ============================================================================
 * WHAT THIS CLASS DOES NOT DO
 * ============================================================================
 * It does not pay anything itself. Every winning bet is paid by
 * RealPrizeSettlementService, the same code the monolithic settle() uses, so there is
 * exactly one definition of how a prize is paid.
 */
final class ChunkedSettlementOrchestrator
{
    public const CHUNK_FLOOR = 50;

    public const CHUNK_CEILING = 5000;

    public const CHUNK_DEFAULT = 500;

    /**
     * A recovery must say why. Short reasons ("retry", "ok") are not a reason.
     */
    private const MIN_REASON_LENGTH = 10;

    private const FAILURE_REASON_LIMIT = 500;

    public function __construct(
        private readonly RealPrizeSettlementService $settlement,
    ) {}

    /**
     * The chunk size used when none is requested, clamped to 50..5000.
     *
     * The floor exists because a chunk of 1 is one transaction per bet, which is
     * the per-slip overhead chunking was meant to remove. The ceiling exists because
     * a chunk of a million is the monolithic transaction again, wearing a cursor.
     */
    public function defaultChunkSize(): int
    {
        $configured = config('lottery.settlement.chunk_size', self::CHUNK_DEFAULT);
        $size = is_numeric($configured) ? (int) $configured : self::CHUNK_DEFAULT;

        return max(self::CHUNK_FLOOR, min(self::CHUNK_CEILING, $size));
    }

    /**
     * The run for a draw, whatever its state. A draw has at most one.
     */
    public function runFor(int $drawId): ?SettlementRun
    {
        return SettlementRun::query()
            ->where('draw_id', $drawId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Open the run for a draw, or return the run that already exists.
     *
     * An existing run is returned as it is. Its chunk size, safety mode and
     * provenance were fixed when it opened, so a later caller asking for a different
     * chunk size gets the original shape of work back, not a reshaped one.
     *
     * The draw is validated BEFORE the row is written, so a refusal leaves no trace
     * that could later be read as "a run was started".
     *
     * @throws \App\Exceptions\DrawLifecycleException
     * @throws SettlementSimulationException
     */
    public function start(int $drawId, ?int $chunkSize = null, ?int $actorId = null): SettlementRun
    {
        $existing = $this->runFor($drawId);

        if ($existing instanceof SettlementRun) {
            return $existing;
        }

        $chunk = $chunkSize ?? $this->defaultChunkSize();

        if ($chunk < 1 || $chunk > self::CHUNK_CEILING) {
            throw new InvalidArgumentException(sprintf(
                'chunk size %d is outside 1..%d',
                $chunk,
                self::CHUNK_CEILING,
            ));
        }

        $draw = $this->drawOrFail($drawId);

        // Refuses an unsettleable draw, and a result that fails its own validation,
        // before anything is written.
        $result = $this->settlement->assertChunkable($draw);

        try {
            $run = SettlementRun::query()->create([
                'draw_id' => $drawId,
                'status' => SettlementRun::STATUS_OPEN,
                'chunk_size' => $chunk,
                'cursor_bet_id' => 0,
                'bets_total' => $this->participantCount($drawId),
                'mode' => RealPrizeSettlementService::MODE,
                'safety_mode' => $this->settlement->safetyMode(),
                'started_at' => now(),
                'started_by' => $actorId,
                'result_fingerprint' => $this->resultFingerprint($drawId, $result),
                'policy_fingerprint' => $this->policyFingerprint(),
            ]);
        } catch (QueryException $exception) {
            // Lost a race with another starter. The unique index on draw_id decided
            // it, so the row that now exists is the run, and it is returned.
            $raced = $this->runFor($drawId);

            if ($raced instanceof SettlementRun) {
                return $raced;
            }

            throw $exception;
        }

        return $run->refresh();
    }

    /**
     * Open (or find) the run and drive it to completion in this process.
     *
     * @throws \App\Exceptions\DrawLifecycleException
     * @throws SettlementSimulationException
     * @throws \App\Exceptions\FinancialException
     */
    public function run(int $drawId, ?int $chunkSize = null, ?int $actorId = null): SettlementRun
    {
        return $this->drive($this->start($drawId, $chunkSize, $actorId));
    }

    /**
     * Settle the next chunk of bets past the cursor, and record it.
     *
     * The cursor is read fresh from the database. A caller holding an out-of-date
     * run object therefore processes the bets that are really still unpaid, not the
     * ones it remembers.
     *
     * @throws \App\Exceptions\DrawLifecycleException
     * @throws SettlementSimulationException
     * @throws \App\Exceptions\FinancialException
     */
    public function runChunk(SettlementRun $run): SettlementRun
    {
        $runId = (int) $run->getKey();
        $current = $this->freshRun($runId);

        if ($current->isComplete()) {
            return $current;
        }

        if (! $current->isOpen()) {
            throw new RuntimeException(sprintf(
                'settlement run %d is %s; it is resumable only through an authorized recovery '
                .'(lottery:settle-draw-chunked --draw=%d --resume --reason="...")',
                $runId,
                (string) $current->status,
                (int) $current->draw_id,
            ));
        }

        $drawId = (int) $current->draw_id;
        $draw = $this->drawOrFail($drawId);

        // Re-validated every chunk: a result changed mid-run is caught before it pays.
        $result = $this->settlement->assertChunkable($draw);

        $pinnedResult = (string) ($current->result_fingerprint ?? '');

        if ($pinnedResult === '' || ! hash_equals($pinnedResult, $this->resultFingerprint($drawId, $result))) {
            throw SettlementSimulationException::resultTampered(
                $drawId,
                'the published result no longer matches the provenance this settlement run was opened against',
                ['settlement_run_id' => $runId],
            );
        }

        $pinnedPolicy = (string) ($current->policy_fingerprint ?? '');

        if ($pinnedPolicy === '' || ! hash_equals($pinnedPolicy, $this->policyFingerprint())) {
            throw SettlementSimulationException::resultTampered(
                $drawId,
                'the payout approval policy changed after this settlement run was opened',
                ['settlement_run_id' => $runId],
            );
        }

        $cursor = (int) $current->cursor_bet_id;

        $this->settlement->settleBetRange(
            draw: $draw,
            result: $result,
            afterBetId: $cursor,
            limit: (int) $current->chunk_size,
            // The mode pinned at open, never the live config: a run that started
            // under DRY_RUN does not start paying because a setting was flipped.
            safetyMode: (string) ($current->safety_mode ?? RealPrizeSettlementService::SAFETY_DISABLED),
            currencySoFar: $current->currency !== null ? Currency::tryFrom((string) $current->currency) : null,
            provenance: [
                'settlement_run_id' => $runId,
                'result_fingerprint' => $pinnedResult,
                'policy_fingerprint' => $pinnedPolicy,
            ],
            checkpoint: function (array $stats) use ($runId, $cursor): void {
                $this->recordChunk($runId, $cursor, $stats);
            },
        );

        return $this->freshRun($runId);
    }

    /**
     * Whether any participant bet remains past the cursor of a run still in progress.
     */
    public function hasMoreWork(SettlementRun $run): bool
    {
        $current = $this->freshRun((int) $run->getKey());

        return ! $current->isComplete() && $this->remainingBets($current) > 0;
    }

    /**
     * Participant bets past the run's cursor. Zero means the run has processed
     * every bet of the draw.
     */
    public function remainingBets(SettlementRun $run): int
    {
        $cursor = (int) SettlementRun::query()
            ->whereKey((int) $run->getKey())
            ->value('cursor_bet_id');

        return Bet::query()
            ->where('draw_id', (int) $run->draw_id)
            ->prizeParticipant()
            ->where('id', '>', $cursor)
            ->count();
    }

    /**
     * Move the draw to Settled, and mark the run completed. Only when every bet has
     * been processed.
     *
     * Idempotent. A run already completed is returned unchanged, and so is a draw
     * already settled, so a retried or repeated finalize cannot move a terminal state
     * twice.
     *
     * @throws RuntimeException when bets remain past the cursor
     */
    public function finalize(SettlementRun $run): SettlementRun
    {
        return DB::transaction(function () use ($run): SettlementRun {
            $current = SettlementRun::query()
                ->whereKey((int) $run->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($current->isComplete()) {
                return $current;
            }

            if (! $current->isOpen()) {
                throw new RuntimeException(sprintf(
                    'cannot finalize run %d: it is %s, not open',
                    (int) $current->getKey(),
                    (string) $current->status,
                ));
            }

            $remaining = $this->remainingBets($current);

            if ($remaining > 0) {
                throw new RuntimeException(sprintf(
                    'cannot finalize run %d: %d bet(s) remain past cursor %d; the draw stays published until every bet is paid',
                    (int) $current->getKey(),
                    $remaining,
                    (int) $current->cursor_bet_id,
                ));
            }

            $this->settlement->finalizeChunkedSettlement($this->drawOrFail((int) $current->draw_id), [
                'selections_written' => (int) $current->selections_written,
                'payouts_created' => (int) $current->payouts_created,
                'bets_settled' => (int) $current->bets_settled,
                'total_prize' => (string) $current->total_prize,
            ]);

            $current->status = SettlementRun::STATUS_COMPLETED;
            $current->completed_at = now();
            $current->save();

            return $current->refresh();
        });
    }

    /**
     * Stop a run, recording why, and write the audit entry.
     *
     * Money-safe to call from any failure path: the failed chunk has already rolled
     * back, so the cursor and totals are exactly what the last committed chunk left.
     * A completed run is never aborted.
     */
    public function abort(SettlementRun $run, Throwable $cause): SettlementRun
    {
        return DB::transaction(function () use ($run, $cause): SettlementRun {
            $current = SettlementRun::query()
                ->whereKey((int) $run->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($current->isComplete()) {
                return $current;
            }

            $reason = $this->boundedReason($cause);

            $current->status = SettlementRun::STATUS_ABORTED;
            $current->failure_reason = $reason;
            $current->save();

            AuditLog::create([
                'user_id' => null,
                'action' => AuditAction::Reconcile,
                'risk_level' => RiskLevel::Critical,
                'auditable_type' => SettlementRun::class,
                'auditable_id' => (int) $current->getKey(),
                'description' => 'settlement_chunked_run_aborted',
                'metadata' => [
                    'action_type' => 'settlement_chunked_run_aborted',
                    'draw_id' => (int) $current->draw_id,
                    'run_id' => (int) $current->getKey(),
                    'cursor_bet_id' => (int) $current->cursor_bet_id,
                    'bets_settled' => (int) $current->bets_settled,
                    'bets_total' => (int) $current->bets_total,
                    'error_class' => $cause::class,
                    'error_code' => $this->errorCodeOf($cause),
                    'reason' => $reason,
                    'outcome' => 'aborted',
                    'resumable' => true,
                ],
            ]);

            return $current->refresh();
        });
    }

    /**
     * Re-open an aborted or interrupted run, WITHOUT driving it.
     *
     * This is the authorized part of recovery, and the queued path uses it on its
     * own: the audit entry must commit before the job is dispatched, so the record of
     * who recovered the run and why always exists before the money moves.
     *
     * @throws InvalidArgumentException when the reason is missing or too short
     * @throws AuthorizationException when the operator lacks process-settlements
     * @throws RuntimeException when the draw has no run
     */
    public function recover(int $drawId, int $operatorId, string $reason): SettlementRun
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'a recovery reason of at least %d characters is required; say what happened and why it is safe to resume',
                self::MIN_REASON_LENGTH,
            ));
        }

        $operator = User::query()->find($operatorId);

        if (! $operator instanceof User || ! $operator->can(AdminAccess::PROCESS_SETTLEMENTS)) {
            // A stored id is not attribution. The recovery must be made by someone
            // who holds the permission to make it.
            throw new AuthorizationException('the operator is not authorized to recover a settlement run');
        }

        $existing = $this->runFor($drawId);

        if (! $existing instanceof SettlementRun) {
            throw new RuntimeException(sprintf('draw %d has no settlement run to recover', $drawId));
        }

        return DB::transaction(function () use ($existing, $operatorId, $reason): SettlementRun {
            $current = SettlementRun::query()
                ->whereKey((int) $existing->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($current->isComplete()) {
                return $current;
            }

            $previousStatus = (string) $current->status;
            $previousFailure = $current->failure_reason;

            $current->status = SettlementRun::STATUS_OPEN;
            $current->failure_reason = null;
            $current->save();

            AuditLog::create([
                'user_id' => $operatorId,
                'action' => AuditAction::Update,
                'risk_level' => RiskLevel::High,
                'auditable_type' => SettlementRun::class,
                'auditable_id' => (int) $current->getKey(),
                'description' => 'settlement_chunked_run_resumed',
                'metadata' => [
                    'action_type' => 'settlement_chunked_run_resumed',
                    'draw_id' => (int) $current->draw_id,
                    'run_id' => (int) $current->getKey(),
                    'reason' => $reason,
                    'outcome' => 'resumed',
                    'previous_status' => $previousStatus,
                    'previous_failure_reason' => $previousFailure,
                    'cursor_bet_id' => (int) $current->cursor_bet_id,
                ],
            ]);

            return $current->refresh();
        });
    }

    /**
     * Recover a run and drive it to completion.
     *
     * @throws InvalidArgumentException
     * @throws AuthorizationException
     * @throws RuntimeException
     * @throws SettlementSimulationException
     */
    public function resume(int $drawId, int $operatorId, string $reason): SettlementRun
    {
        return $this->drive($this->recover($drawId, $operatorId, $reason));
    }

    /**
     * A one-line account of a run, for the console and the logs.
     */
    public function describe(SettlementRun $run): string
    {
        return sprintf(
            'run %d draw %d [%s] %s: %d/%d bets (%s%%), cursor %d, chunk %d, safety %s, prize %s %s',
            (int) $run->getKey(),
            (int) $run->draw_id,
            (string) $run->status,
            (string) $run->mode,
            (int) $run->bets_settled,
            (int) $run->bets_total,
            $run->percentComplete() === null ? 'unknown' : number_format((float) $run->percentComplete(), 2, '.', ''),
            (int) $run->cursor_bet_id,
            (int) $run->chunk_size,
            (string) ($run->safety_mode ?? 'unknown'),
            (string) $run->total_prize,
            (string) ($run->currency ?? ''),
        );
    }

    /**
     * Drive a run from its cursor to its end, then finalize it.
     *
     * A failure aborts the run, unless it is a transient concurrency conflict: that
     * one means another worker is driving the run right now, and aborting it would
     * stop a healthy run.
     */
    private function drive(SettlementRun $run): SettlementRun
    {
        $current = $run;

        try {
            while ($this->hasMoreWork($current)) {
                $current = $this->runChunk($current);
            }

            return $this->finalize($current);
        } catch (Throwable $cause) {
            if (! $cause instanceof SettlementRunMovedException) {
                $fresh = SettlementRun::query()->whereKey((int) $run->getKey())->first();

                if ($fresh instanceof SettlementRun && $fresh->isOpen()) {
                    $this->abort($fresh, $cause);
                }
            }

            throw $cause;
        }
    }

    /**
     * Runs inside the chunk's transaction, through the settleBetRange checkpoint.
     *
     * The lock and the cursor comparison are the guard against two workers driving one
     * run. If the cursor is not where this chunk started, another worker got there
     * first. Throwing rolls the chunk back, payouts included, so this worker's money
     * cannot land twice.
     *
     * @param  array<string, mixed>  $stats
     */
    private function recordChunk(int $runId, int $expectedCursor, array $stats): void
    {
        $row = SettlementRun::query()
            ->whereKey($runId)
            ->lockForUpdate()
            ->first();

        if (! $row instanceof SettlementRun
            || ! $row->isOpen()
            || (int) $row->cursor_bet_id !== $expectedCursor) {
            throw new SettlementRunMovedException(sprintf(
                'settlement run %d moved while this chunk was in flight (expected cursor %d); the chunk rolled back and no money moved',
                $runId,
                $expectedCursor,
            ));
        }

        if ((int) $stats['bets_seen'] === 0) {
            // Nothing past the cursor: no progress to record, and no chunk to count.
            return;
        }

        $row->cursor_bet_id = (int) $stats['last_bet_id'];
        $row->bets_settled = (int) $row->bets_settled + (int) $stats['bets_settled'];
        $row->selections_evaluated = (int) $row->selections_evaluated + (int) $stats['selections_evaluated'];
        $row->selections_written = (int) $row->selections_written + (int) $stats['selections_written'];
        $row->winning_selections = (int) $row->winning_selections + (int) $stats['winning_selections'];
        $row->payouts_created = (int) $row->payouts_created + (int) $stats['payouts_created'];

        // BCMath throughout. A total computed in binary floating point is a total
        // nobody can reconcile.
        $row->total_stake = bcadd((string) $row->total_stake, (string) $stats['total_stake_delta'], 2);
        $row->total_prize = bcadd((string) $row->total_prize, (string) $stats['total_prize_delta'], 2);

        if ($stats['currency'] instanceof Currency) {
            $row->currency = $stats['currency']->value;
        }

        $row->chunks_processed = (int) $row->chunks_processed + 1;
        $row->last_chunk_at = now();
        $row->save();
    }

    private function freshRun(int $runId): SettlementRun
    {
        return SettlementRun::query()->whereKey($runId)->firstOrFail();
    }

    private function drawOrFail(int $drawId): Draw
    {
        $draw = Draw::query()->find($drawId);

        if (! $draw instanceof Draw) {
            throw new RuntimeException(sprintf('draw %d does not exist', $drawId));
        }

        return $draw;
    }

    private function participantCount(int $drawId): int
    {
        return Bet::query()
            ->where('draw_id', $drawId)
            ->prizeParticipant()
            ->count();
    }

    /**
     * A fingerprint of everything the run pays from: the published numbers, the first
     * prize and bottom two, the four-eyes ingestion record, and the winning-number
     * rows. Any change after open changes it, and every chunk checks it.
     */
    private function resultFingerprint(int $drawId, DrawResultData $result): string
    {
        $row = DrawResult::query()->where('draw_id', $drawId)->first();
        $metadata = $row instanceof DrawResult && is_array($row->metadata) ? $row->metadata : [];

        $numbers = WinningNumber::query()
            ->where('draw_id', $drawId)
            ->orderBy('id')
            ->get(['number', 'prize_tier'])
            ->map(static fn (WinningNumber $winning): array => [
                'number' => (string) $winning->number,
                'tier' => $winning->prize_tier,
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'draw_id' => $drawId,
            'first_prize' => $result->firstPrize,
            'bottom_two' => $result->bottomTwo,
            'four_eyes' => $metadata['four_eyes'] ?? null,
            'winning_numbers' => $numbers,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * A fingerprint of the approval thresholds that decide which winners need a
     * human. The safety mode is deliberately not in it: that is pinned separately,
     * and changing it is handled by the mode-pinning rule, not as a policy change.
     */
    private function policyFingerprint(): string
    {
        return hash('sha256', json_encode([
            'auto_approve_below' => (string) config('finance.prize_payout.auto_approve_below', '1000000.00'),
            'require_approval_at_or_above' => (string) config('finance.prize_payout.require_approval_at_or_above', '1000000.00'),
        ], JSON_THROW_ON_ERROR));
    }

    private function boundedReason(Throwable $cause): string
    {
        $message = trim($cause->getMessage());
        $reason = $message === ''
            ? $cause::class
            : sprintf('%s: %s', $cause::class, $message);

        return mb_substr($reason, 0, self::FAILURE_REASON_LIMIT);
    }

    private function errorCodeOf(Throwable $cause): string
    {
        if (method_exists($cause, 'errorCode')) {
            return (string) $cause->errorCode();
        }

        if ($cause instanceof QueryException) {
            return (string) ($cause->errorInfo[0] ?? 'database_error');
        }

        return 'unclassified';
    }
}
