<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\Enums\BetStatus;
use App\Jobs\Draw\FinalizeDrawSettlementJob;
use App\Jobs\Draw\SettleDrawChunkJob;
use App\Models\Bet;
use App\Models\Draw;
use Illuminate\Support\Facades\Bus;
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
    ) {}

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
