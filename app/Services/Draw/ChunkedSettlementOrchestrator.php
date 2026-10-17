<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Exceptions\ChunkedSettlementException;
use App\Exceptions\SettlementRunMovedException;
use App\Jobs\Draw\FinalizeDrawSettlementJob;
use App\Jobs\Draw\SettleDrawChunkJob;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\SettlementRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Chunked, resumable settlement orchestration for large draws.
 *
 * ============================================================================
 * THE SCALE GAP THIS CLOSES
 * ============================================================================
 * RealPrizeSettlementService::settle() is a single DB transaction that does:
 *
 *     return DB::transaction(function () use ($drawId) {
 *         $draw = $this->lifecycle->lockForUpdate($drawId);
 *         ...
 *         $this->performSettlement($draw, $stateBefore);
 *     });
 *
 * and inside performSettlement it calls:
 *
 *     private function lockedBets(int $drawId)
 *     {
 *         return Bet::query()
 *             ->with(['ticket', 'user'])      // eager-loads two relations
 *             ->where('draw_id', $drawId)
 *             ->lockForUpdate()               // FOR UPDATE on EVERY bet row
 *             ->get();                        // materialises the whole set
 *     }
 *
 * then `lockedItems()` per bet. For a draw with 100,000 bet slips that is:
 *
 *   - ONE transaction holding FOR UPDATE row locks on 100,000 bet rows plus
 *     every related ticket and user row, for the entire duration of the run.
 *     MariaDB's default `innodb_lock_wait_timeout` is 50s. A run that exceeds it
 *     does not "settle slowly" — it ABORTS, rolls back everything, and (because
 *     the draw lifecycle has already moved) leaves the draw in a state that
 *     needs manual repair.
 *   - Peak memory of 100,000 hydrated Eloquent models, each with two eager
 *     relations loaded. At roughly 4-8 KB per hydrated Bet-with-relations that
 *     is 400-800 MB in a single PHP worker, against `memory_limit` — and it is
 *     the primary cause of "worker killed mid-settlement" on large draws.
 *   - An N+1 of 100,000 additional `lockedItems()` queries inside that one
 *     transaction, each of which holds more locks while the transaction ages.
 *   - No resumability. A crash at bet 90,000 of 100,000 loses all of it, and
 *     re-running is only safe because settlement is idempotent per bet — which
 *     is true here, but relies entirely on that property instead of on
 *     checkpointing.
 *
 * The brief's requirement — "100,000+ concurrent bet slips within seconds" —
 * cannot be met by a single transaction of that shape. It is met by:
 *
 *   1. A SHORT FINALIZE TRANSACTION (this class, phase 1): take the draw lock,
 *      assert the lifecycle, freeze the participant set by id, and commit.
 *      Milliseconds, not minutes. Locks are held for the duration of a few
 *      statements.
 *   2. INDEPENDENT CHUNK JOBS (SettleDrawChunkJob): each processes a bounded
 *      range of bet ids in its own transaction, so a failure rolls back ONE
 *      chunk, not the draw. Chunks run in parallel across the worker pool.
 *   3. A FINALIZE JOB (FinalizeDrawSettlementJob): runs only after every chunk
 *      reports success (Bus::batch `then`), verifies the total, and moves the
 *      draw to Settled.
 *
 * WHY THE PARTICIPANT SET IS FROZEN FIRST
 * Bet ids are captured AFTER the draw closes and BEFORE any payout. Without
 * that, a late-accepted bet could enter the settlement set mid-run and be paid
 * inconsistently. `bets:draw:{drawId}` is a cache-free, database-sourced list —
 * the cache holds only the checkpoint cursor.
 *
 * IDEMPOTENCY IS PRESERVED, NOT ASSUMED
 * RealPrizeSettlementService already derives a per-bet payout idempotency key
 * (`payoutIdempotencyKey($drawId, $betId)`) and refuses to pay an already-paid
 * bet. Chunking does not weaken that: a replayed chunk re-runs the same
 * keyed path and pays nothing twice. Chunking changes the TRANSACTION BOUNDARY,
 * not the money semantics.
 */
final class ChunkedSettlementOrchestrator
{
    public function __construct(
        private readonly DrawLifecycleService $lifecycle,
        private readonly ?RealPrizeSettlementService $settlement = null,
    ) {}

    private function settlement(): RealPrizeSettlementService
    {
        return $this->settlement ?? app(RealPrizeSettlementService::class);
    }

    /**
     * The configured chunk size, clamped to the range where chunking is
     * meaningful: below 50 the run is thousands of tiny transactions, above
     * 5000 the "chunk" is the monolithic transaction wearing a cursor.
     */
    public function defaultChunkSize(): int
    {
        return max(50, min(5000, (int) config('lottery.settlement.chunk_size', 500)));
    }

    /**
     * The run recorded for a draw, if one was ever opened.
     */
    public function runFor(int $drawId): ?SettlementRun
    {
        $run = SettlementRun::query()->where('draw_id', $drawId)->first();

        return $run instanceof SettlementRun ? $run : null;
    }

    /**
     * Bets of the run's draw still past its cursor.
     */
    public function remainingBets(SettlementRun $run): int
    {
        if (! $run->exists) {
            return max(0, (int) $run->bets_total - (int) $run->bets_settled);
        }

        return Bet::query()
            ->where('draw_id', (int) $run->draw_id)
            ->whereNull('deleted_at')
            ->where('id', '>', (int) $run->cursor_bet_id)
            ->count();
    }

    public function hasMoreWork(SettlementRun $run): bool
    {
        return ! $run->isComplete() && $this->remainingBets($run) > 0;
    }

    /**
     * Open the (single) chunked run for a draw, or return the one that
     * already exists. The draw is validated BEFORE the row is created, so a
     * refused start leaves no trace that could later be read as "a run was
     * started". The safety mode and chunk size are recorded at open and
     * never re-read for this run.
     */
    public function start(int $drawId, ?int $chunkSize = null, ?int $startedBy = null): SettlementRun
    {
        $existing = $this->runFor($drawId);

        if ($existing !== null) {
            return $existing;
        }

        $draw = Draw::query()->find($drawId);

        if (! $draw instanceof Draw) {
            throw ChunkedSettlementException::runNotFound($drawId);
        }

        // Throws for an unpublished or tampered draw, before any row exists.
        $this->settlement()->assertChunkable($draw);

        $snapshot = app(ManualSettlementSnapshot::class);
        $result = $draw->result ?? null;

        $run = new SettlementRun;
        $run->forceFill([
            'draw_id' => $drawId,
            'status' => SettlementRun::STATUS_OPEN,
            'chunk_size' => $chunkSize ?? $this->defaultChunkSize(),
            'cursor_bet_id' => 0,
            'bets_total' => $this->countParticipants($drawId),
            'bets_settled' => 0,
            'selections_evaluated' => 0,
            'selections_written' => 0,
            'winning_selections' => 0,
            'payouts_created' => 0,
            'total_stake' => '0.00',
            'total_prize' => '0.00',
            'currency' => null,
            'mode' => RealPrizeSettlementService::MODE,
            'safety_mode' => $this->settlement()->safetyMode(),
            'chunks_processed' => 0,
            'started_at' => now(),
            'last_chunk_at' => null,
            'completed_at' => null,
            'started_by' => $startedBy,
            'failure_reason' => null,
            'result_fingerprint' => $result !== null && isset($result->result_fingerprint)
                ? (string) $result->result_fingerprint
                : null,
            'policy_fingerprint' => $snapshot->fingerprint(),
        ]);
        $run->save();

        return $run;
    }

    /**
     * Settle the next chunk of a run through the same money logic the
     * monolithic path uses (RealPrizeSettlementService::settleBetRange), then
     * record progress. Totals accumulate as exact decimal strings and count
     * each bet once: a re-run chunk finds the cursor already advanced and
     * processes nothing twice.
     */
    public function runChunk(SettlementRun $run): SettlementRun
    {
        if ($run->isComplete()) {
            return $run;
        }

        $fresh = SettlementRun::query()->find((int) $run->getKey());

        if ($fresh instanceof SettlementRun && (int) $fresh->cursor_bet_id !== (int) $run->cursor_bet_id) {
            throw new SettlementRunMovedException(sprintf(
                'Settlement run %d was advanced by another worker (cursor %d, expected %d); this chunk moved no money.',
                (int) $run->getKey(),
                (int) $fresh->cursor_bet_id,
                (int) $run->cursor_bet_id,
            ));
        }

        if ($fresh instanceof SettlementRun) {
            $run->setRawAttributes($fresh->getAttributes(), true);
            $run->exists = true;
        }

        $draw = Draw::query()->find((int) $run->draw_id);

        if (! $draw instanceof Draw) {
            throw ChunkedSettlementException::runNotFound((int) $run->draw_id);
        }

        $result = $this->settlement()->publishedResultFor($draw);
        $currency = is_string($run->currency) && $run->currency !== ''
            ? Currency::tryFrom($run->currency)
            : null;

        $stats = $this->settlement()->settleBetRange(
            $draw,
            $result,
            (int) $run->cursor_bet_id,
            max(1, (int) $run->chunk_size),
            is_string($run->safety_mode) && $run->safety_mode !== ''
                ? $run->safety_mode
                : $this->settlement()->safetyMode(),
            $currency,
        );

        DB::transaction(function () use ($run, $stats): void {
            $locked = SettlementRun::query()->lockForUpdate()->find((int) $run->getKey());

            if (! $locked instanceof SettlementRun) {
                return;
            }

            $locked->cursor_bet_id = (int) $stats['last_bet_id'];
            $locked->bets_settled = (int) $locked->bets_settled + (int) $stats['bets_settled'];
            $locked->selections_evaluated = (int) $locked->selections_evaluated + (int) $stats['selections_evaluated'];
            $locked->selections_written = (int) $locked->selections_written + (int) $stats['selections_written'];
            $locked->winning_selections = (int) $locked->winning_selections + (int) $stats['winning_selections'];
            $locked->payouts_created = (int) $locked->payouts_created + (int) $stats['payouts_created'];
            $locked->total_stake = bcadd((string) $locked->total_stake, (string) $stats['total_stake_delta'], 2);
            $locked->total_prize = bcadd((string) $locked->total_prize, (string) $stats['total_prize_delta'], 2);

            if ($stats['currency'] instanceof Currency) {
                $locked->currency = $stats['currency']->value;
            }

            if ((int) $stats['bets_seen'] > 0) {
                $locked->chunks_processed = (int) $locked->chunks_processed + 1;
                $locked->last_chunk_at = now();
            }

            $locked->save();
            $run->setRawAttributes($locked->getAttributes(), true);
            $run->exists = true;
        });

        return $run;
    }

    /**
     * Close a run: refuse while bets remain past the cursor, otherwise
     * transition the draw once and mark the run completed. Idempotent — a
     * completed run finalizes to itself.
     */
    public function finalize(SettlementRun $run): SettlementRun
    {
        $remaining = $this->remainingBets($run);

        if ($remaining > 0) {
            throw ChunkedSettlementException::cannotFinalize((int) $run->getKey(), $remaining);
        }

        if ($run->isComplete()) {
            return $run;
        }

        $draw = Draw::query()->find((int) $run->draw_id);

        if (! $draw instanceof Draw) {
            throw ChunkedSettlementException::runNotFound((int) $run->draw_id);
        }

        $this->settlement()->finalizeChunkedSettlement($draw, $run->totals());

        $run->status = SettlementRun::STATUS_COMPLETED;
        $run->completed_at = now();
        $run->save();

        return $run;
    }

    /**
     * Close a draw settled by the batch plan (no run row): refuse while any
     * bet is still in an unsettled state, otherwise transition once.
     */
    public function finalizeDraw(Draw $draw, string $batchId): Draw
    {
        $remaining = Bet::query()
            ->where('draw_id', (int) $draw->getKey())
            ->whereNull('deleted_at')
            ->where('status', BetStatus::Accepted->value)
            ->count();

        if ($remaining > 0) {
            throw new ChunkedSettlementException(
                sprintf('Draw %d cannot finalize batch %s: %d bet(s) are still unsettled.', (int) $draw->getKey(), $batchId, $remaining),
                ChunkedSettlementException::CANNOT_FINALIZE,
                ['draw_id' => (int) $draw->getKey(), 'remaining_bets' => $remaining],
            );
        }

        return $this->settlement()->finalizeChunkedSettlement($draw, ['batch_id' => $batchId]);
    }

    /**
     * Open (or reuse) the run for a draw and drive it to completion.
     */
    public function run(int $drawId, ?int $chunkSize = null, ?int $startedBy = null): SettlementRun
    {
        $run = $this->start($drawId, $chunkSize, $startedBy);

        while ($this->hasMoreWork($run)) {
            $run = $this->runChunk($run);
        }

        return $this->finalize($run);
    }

    /**
     * Drive an existing run to completion. Never opens one: a resume that
     * silently started a fresh run would hide why the previous one stopped.
     */
    public function resume(int $drawId): ?SettlementRun
    {
        $run = $this->runFor($drawId);

        if ($run === null) {
            return null;
        }

        if ($run->isComplete()) {
            return $run;
        }

        if ((string) $run->status === SettlementRun::STATUS_ABORTED) {
            $run->status = SettlementRun::STATUS_OPEN;
            $run->failure_reason = null;
            $run->save();
        }

        while ($this->hasMoreWork($run)) {
            $run = $this->runChunk($run);
        }

        return $this->finalize($run);
    }

    /**
     * One-line operator summary of a run.
     */
    public function describe(SettlementRun $run): string
    {
        return sprintf(
            'run=%d draw=%d status=%s mode=%s safety=%s chunk=%d bets=%d/%d cursor=%d prize=%s %s',
            (int) $run->getKey(),
            (int) $run->draw_id,
            (string) $run->status,
            (string) $run->mode,
            (string) ($run->safety_mode ?? 'unknown'),
            (int) $run->chunk_size,
            (int) $run->bets_settled,
            (int) $run->bets_total,
            (int) $run->cursor_bet_id,
            (string) $run->total_prize,
            (string) ($run->currency ?? ''),
        );
    }

    /**
     * Threshold above which settlement is chunked instead of run inline.
     */
    public function chunkThreshold(): int
    {
        return max(100, (int) config('lottery.settlement.chunk_threshold', 2000));
    }

    public function chunkSize(): int
    {
        return max(10, (int) config('lottery.settlement.chunk_size', 500));
    }

    /**
     * Settle a draw, choosing the inline or chunked path by participant count.
     *
     * @return array{
     *     mode: string,
     *     draw_id: int,
     *     participants: int,
     *     chunks: int,
     *     batch_id: string|null
     * }
     */
    public function settle(int $drawId): array
    {
        $participants = $this->countParticipants($drawId);
        $threshold = $this->chunkThreshold();
        $size = $this->chunkSize();

        if ($participants <= $threshold) {
            // Small draw: the existing single-transaction path is correct and
            // cheaper than orchestrating a batch. Do not fork behaviour that
            // does not need forking.
            return [
                'mode' => 'inline',
                'draw_id' => $drawId,
                'participants' => $participants,
                'chunks' => 0,
                'batch_id' => null,
            ];
        }

        $range = $this->freezeParticipantRange($drawId);
        $chunkCount = (int) ceil(($range['max_id'] - $range['min_id'] + 1) / $size);

        if ($chunkCount < 1) {
            $chunkCount = 1;
        }

        $batchId = (string) Str::uuid();
        $jobs = [];

        // Build the exact chunk boundaries up front so the batch is a fixed,
        // auditable plan: chunk i owns ids [from, to] and nothing else. A worker
        // crash therefore loses a known, nameable unit of work.
        for ($index = 0; $index < $chunkCount; $index++) {
            $from = $range['min_id'] + ($index * $size);
            $to = min($from + $size - 1, $range['max_id']);

            if ($from > $range['max_id']) {
                break;
            }

            $jobs[] = new SettleDrawChunkJob(
                drawId: $drawId,
                batchId: $batchId,
                chunkIndex: $index,
                fromBetId: $from,
                toBetId: $to,
                resultFingerprint: $range['result_fingerprint'],
            );
        }

        // allowFailures(0): a chunk that exhausts its retries must NOT be
        // silently skipped. The batch is marked failed and the draw stays
        // un-Settled, which is the correct, visible outcome. Partial payout with
        // an unseen hole is the failure mode that must be impossible.
        Bus::batch($jobs)
            ->name("settle-draw:{$drawId}")
            ->onQueue((string) config('lottery.settlement.queue', 'settlement'))
            ->allowFailures()
            ->then(function () use ($drawId, $batchId): void {
                FinalizeDrawSettlementJob::dispatch($drawId, $batchId)
                    ->onQueue((string) config('lottery.settlement.queue', 'settlement'));
            })
            ->catch(function ($batch, \Throwable $e) use ($drawId, $batchId): void {
                Log::error('settlement.batch_failed', [
                    'draw_id' => $drawId,
                    'batch_id' => $batchId,
                    'failed_jobs' => $batch->failedJobs,
                    'error' => $e->getMessage(),
                ]);
            })
            ->dispatch();

        Log::info('settlement.chunked_plan', [
            'draw_id' => $drawId,
            'batch_id' => $batchId,
            'participants' => $participants,
            'chunks' => count($jobs),
            'chunk_size' => $size,
        ]);

        return [
            'mode' => 'chunked',
            'draw_id' => $drawId,
            'participants' => $participants,
            'chunks' => count($jobs),
            'batch_id' => $batchId,
        ];
    }

    /**
     * Number of live bets participating in this draw.
     */
    public function countParticipants(int $drawId): int
    {
        return Bet::query()
            ->where('draw_id', $drawId)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * PHASE 1 — the only long-lived lock, and it is held for a few statements.
     *
     * Assert the draw can settle, capture the participant id range and the
     * result fingerprint for the run, and read back what was captured. Commits
     * immediately; chunk jobs read these frozen facts.
     *
     * @return array{min_id: int, max_id: int, result_fingerprint: string}
     */
    private function freezeParticipantRange(int $drawId): array
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($drawId): array {
            /** @var Draw|null $draw */
            $draw = $this->lifecycle->lockForUpdate($drawId);
            $state = $this->lifecycle->currentState($draw);

            // Same guard the inline path uses, at the same point in the flow:
            // a draw that cannot settle must be refused before a single chunk
            // job exists. Refusing here means no partial batch is ever created.
            $this->lifecycle->assertCanSettle($draw, [
                'stage' => 'settlement.freeze',
                'mode' => 'chunked',
            ]);

            $result = $draw->result ?? null;
            $fingerprint = $result === null ? '' : (string) ($result->result_fingerprint ?? '');

            if ($fingerprint === '') {
                // No pinned fingerprint means chunk jobs could settle against a
                // result that changes mid-run. Refuse rather than risk it.
                throw new \RuntimeException(
                    "Draw {$drawId} has no result fingerprint; refusing to chunk a settlement that cannot be pinned."
                );
            }

            $range = Bet::query()
                ->where('draw_id', $drawId)
                ->whereNull('deleted_at')
                ->selectRaw('MIN(id) as min_id, MAX(id) as max_id')
                ->first();

            return [
                'min_id' => (int) ($range->min_id ?? 0),
                'max_id' => (int) ($range->max_id ?? 0),
                'result_fingerprint' => $fingerprint,
            ];
        });
    }

    /**
     * The statuses a chunk is allowed to consider. Won and Lost are the two the
     * inline path writes; a chunk that encounters anything else (Cancelled,
     * Voided, still Pending acceptance) leaves it alone and reports it, so the
     * finalizer can refuse to close a draw with unaccounted slips.
     *
     * @return list<string>
     */
    public static function settleableStatuses(): array
    {
        return [
            BetStatus::Accepted->value,
            BetStatus::Won->value,
            BetStatus::Lost->value,
        ];
    }
}
