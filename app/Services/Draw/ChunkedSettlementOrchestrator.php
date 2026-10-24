<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\Enums\BetStatus;
use App\Jobs\Draw\FinalizeDrawSettlementJob;
use App\Jobs\Draw\SettleDrawChunkJob;
use App\Models\Bet;
use App\Models\Draw;
use App\Enums\Currency;
use App\Models\Payout;
use App\Models\SettlementRun;
use Illuminate\Database\QueryException;
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
        private readonly RealPrizeSettlementService $settlement,
    ) {}

    /**
     * A one-line-per-fact description of a run, for an operator at a terminal.
     *
     * Deliberately says the SAFETY MODE out loud: an operator reading "1 payout,
     * 9000.00 THB" needs to know whether that money actually reached a wallet or
     * is a recorded obligation under DRY_RUN. Those two outcomes look identical
     * in every other number on the row.
     */
    public function describe(SettlementRun $run): string
    {
        $total = (int) $run->bets_total;
        $done = (int) $run->bets_settled;
        $percent = $run->percentComplete();

        return sprintf(
            'run %d draw %d | status=%s | safety=%s | %s | chunk=%d cursor=%d | bets %d/%d (%s) | chunks=%d | payouts=%d prize=%s %s | remaining=%d%s',
            (int) $run->getKey(),
            (int) $run->draw_id,
            (string) $run->status,
            (string) ($run->safety_mode ?? 'unknown'),
            (string) $run->mode,
            (int) $run->chunk_size,
            (int) $run->cursor_bet_id,
            $done,
            $total,
            $percent === null ? 'unknown' : number_format($percent, 2).'%',
            (int) $run->chunks_processed,
            (int) $run->payouts_created,
            (string) $run->total_prize,
            (string) ($run->currency ?? ''),
            $this->remainingBets($run),
            is_string($run->failure_reason) && $run->failure_reason !== ''
                ? ' | failure='.$run->failure_reason
                : '',
        );
    }

    /**
     * The chunk size to use when a caller does not name one.
     *
     * Clamped on BOTH sides, and the two ends mean different things. Below 50 a
     * "chunk" is a handful of bets and the per-chunk transaction, row locks and
     * progress write cost more than the settlement they carry; above 5000 the
     * chunk is the monolithic transaction again, wearing a cursor. A configured
     * value outside that band is not obeyed, it is clamped, because a config
     * typo must not silently turn chunking off.
     */
    public function defaultChunkSize(): int
    {
        return max(50, min(5000, (int) config('lottery.settlement.chunk_size', 500)));
    }

    /**
     * The settlement run that owns this draw, if one has ever been opened.
     *
     * At most one can exist — draw_id is UNIQUE on settlement_runs — so this is
     * a read of a fact, not a search.
     */
    public function runFor(int $drawId): ?SettlementRun
    {
        $run = SettlementRun::query()->where('draw_id', $drawId)->first();

        return $run instanceof SettlementRun ? $run : null;
    }

    /**
     * Open (or read back) the run that owns this draw.
     *
     * THE RUN ROW IS THE CLAIM, NOT A NOTE. `draw_id` is UNIQUE, so the insert
     * is the arbitration: a second scheduler, a second worker or an operator
     * pressing the button twice does not open a second run, it fails to insert
     * and is handed the run that already exists. That is why the existing row is
     * returned with its ORIGINAL chunk size and ORIGINAL safety mode — a caller
     * arriving later cannot reshape work that is already in flight.
     *
     * A refusal happens BEFORE the insert. Opening a run against a draw that
     * cannot settle, or whose published result is not intact, must leave no
     * trace: a run row is read by operators, by settle() (as the overlap guard),
     * and by resume(), so a stray row would be a settlement that looks started
     * and never was.
     *
     * @throws \Throwable when the draw cannot be chunk-settled
     */
    public function start(int $drawId, ?int $chunkSize = null, ?int $startedBy = null): SettlementRun
    {
        $existing = $this->runFor($drawId);

        if ($existing instanceof SettlementRun) {
            return $existing;
        }

        $draw = Draw::query()->find($drawId);

        if (! $draw instanceof Draw) {
            throw new \RuntimeException(sprintf('Draw %d does not exist; cannot open a settlement run.', $drawId));
        }

        // Lifecycle + published result, validated once, before any chunk exists
        // and before the run row. Throws on a draw that is not published, is
        // already settled, or whose winning_numbers rows no longer agree with
        // the published numbers.
        $this->settlement->assertChunkable($draw);

        $size = $chunkSize === null ? $this->defaultChunkSize() : max(1, $chunkSize);
        $frozen = $this->freezeParticipants($drawId);

        $attributes = [
            'draw_id' => $drawId,
            'status' => SettlementRun::STATUS_OPEN,
            'chunk_size' => $size,
            'cursor_bet_id' => 0,
            'bets_total' => $frozen['count'],
            'bets_settled' => 0,
            'selections_evaluated' => 0,
            'selections_written' => 0,
            'winning_selections' => 0,
            'payouts_created' => 0,
            'total_stake' => '0.00',
            'total_prize' => '0.00',
            'currency' => $draw->currency instanceof Currency ? $draw->currency->value : (string) $draw->currency,
            'mode' => RealPrizeSettlementService::MODE,
            // PINNED, NOT RE-READ. Every later chunk pays under this mode, so a
            // config edit mid-run cannot start paying under LIVE in chunk 4 of a
            // run that opened under DRY_RUN. (A later switch to a STRICTER mode
            // still applies — that is handled per chunk.)
            'safety_mode' => $this->settlement->safetyMode(),
            // The approval threshold in force at open, so raising it mid-run
            // cannot turn a payout that needed approval into an automatic one.
            'approval_threshold' => $this->settlement->thresholdAsString(
                (string) config('finance.prize_payout.auto_approve_below', '1000000.00'),
            ),
            // The frozen participant set and its selection inputs, plus the
            // highest bet id that belonged to the draw at open. A bet added,
            // removed, emptied or edited mid-run is then visible instead of
            // silently settled by a later chunk.
            'max_bet_id' => $frozen['max_id'],
            'participants_fingerprint' => $frozen['fingerprint'],
            'result_fingerprint' => $this->resultFingerprint($drawId),
            'outcomes_fingerprint' => null,
            'chunks_processed' => 0,
            'started_at' => now(),
            'started_by' => $startedBy,
        ];

        try {
            $id = DB::table('settlement_runs')->insertGetId($attributes + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Another process won the unique index. Its run is the run.
            $won = $this->runFor($drawId);

            if ($won instanceof SettlementRun) {
                return $won;
            }

            throw $e;
        }

        $run = SettlementRun::query()->find($id);

        if (! $run instanceof SettlementRun) {
            throw new \RuntimeException(sprintf('Settlement run %s disappeared immediately after insert.', (string) $id));
        }

        Log::info('settlement.run_opened', [
            'draw_id' => $drawId,
            'run_id' => (int) $run->getKey(),
            'chunk_size' => $size,
            'bets_total' => (int) $run->bets_total,
            'safety_mode' => $run->safety_mode,
            'approval_threshold' => (string) $run->approval_threshold,
        ]);

        return $run;
    }

    /**
     * Reopen an aborted run, so an operator can resume it.
     *
     * AN UNPINNED RUN IS NOT RESURFACED, IT IS REFUSED. A run without a
     * participants fingerprint and a result fingerprint cannot prove what it was
     * opened against — a row written by an older build, or edited by hand. It
     * could not have been proved when it was created either, so resuming it
     * would be guessing at the participant set and the numbers, and the guesses
     * would be paid. Refusing keeps the row exactly as it was found.
     */
    public function reopen(SettlementRun $run): SettlementRun
    {
        $run->refresh();
        $this->assertPinned($run);

        if ($run->isComplete()) {
            return $run;
        }

        $run->forceFill([
            'status' => SettlementRun::STATUS_OPEN,
            'failure_reason' => null,
        ])->save();

        Log::info('settlement.run_reopened', [
            'draw_id' => (int) $run->draw_id,
            'run_id' => (int) $run->getKey(),
            'cursor_bet_id' => (int) $run->cursor_bet_id,
        ]);

        return $run->refresh();
    }

    /**
     * Settle the next slice of the run, in a transaction of its own.
     *
     * The money work is RealPrizeSettlementService::settleBetRange(), the same
     * code the monolithic path pays winners with — chunking changes the
     * transaction boundary (and below, the boundary of the CHECKPOINT), never the
     * money semantics.
     *
     * MONEY AND CHECKPOINT ARE ONE TRANSACTION. `participating: true` hands the
     * root transaction to this method so the payouts, the wallet credits, the
     * settled bet rows and the run's cursor commit together or not at all. The
     * opposite — pay the chunk, then try to write the cursor — has a failure
     * mode nobody can reconcile: money left the treasury, the run still points
     * at the old cursor, and the next chunk pays those same bets again.
     *
     * Every guard below is re-proved per chunk, not assumed from start().
     */
    public function runChunk(SettlementRun $run, ?int $expectedCursor = null): SettlementRun
    {
        if (DB::transactionLevel() > 0) {
            // Each chunk must own its transaction, for the same reason settle()
            // refuses: inside a caller's transaction a failure here could be
            // swallowed by an outer catch and the partial chunk — including
            // paid-out money — committed anyway.
            throw new \RuntimeException('A settlement chunk must own its transaction; refuse to run one inside a caller transaction.');
        }

        $run->refresh();

        if ($run->isComplete()) {
            return $run;
        }

        if ((string) $run->status !== SettlementRun::STATUS_OPEN) {
            throw new \RuntimeException(sprintf(
                'Settlement run %d is %s; only an open run accepts chunks. Use --resume to reopen an interrupted run deliberately.',
                (int) $run->getKey(),
                (string) $run->status,
            ));
        }

        if ($expectedCursor !== null && (int) $run->cursor_bet_id !== $expectedCursor) {
            if ((int) $run->cursor_bet_id > $expectedCursor) {
                // A DUPLICATE DELIVERY of a chunk that already ran, not a plan for
                // different work. Nothing to do: the range it names is settled and
                // its checkpoint is committed. Throwing here would fail a job that
                // did nothing wrong, and re-running it would be worse.
                Log::info('settlement.chunk_duplicate_delivery', [
                    'draw_id' => (int) $run->draw_id,
                    'run_id' => (int) $run->getKey(),
                    'expected_cursor' => $expectedCursor,
                    'recorded_cursor' => (int) $run->cursor_bet_id,
                ]);

                return $run;
            }

            throw new \RuntimeException(sprintf(
                'Settlement run %d is at cursor %d; the delivered chunk expected cursor %d.',
                (int) $run->getKey(),
                (int) $run->cursor_bet_id,
                $expectedCursor,
            ));
        }

        $draw = Draw::query()->find((int) $run->draw_id);

        if (! $draw instanceof Draw) {
            throw new \RuntimeException(sprintf(
                'Draw %d does not exist; settlement run %d cannot continue.',
                (int) $run->draw_id,
                (int) $run->getKey(),
            ));
        }

        $this->assertPinned($run);
        $this->assertParticipantsUnchanged($run);
        $this->assertResultUnchanged($run);

        $after = (int) $run->cursor_bet_id;
        $size = max(1, (int) $run->chunk_size);
        $currency = $run->currency === null ? null : Currency::tryFrom((string) $run->currency);
        $safety = $this->effectiveSafetyMode($run);
        $threshold = $this->effectiveApprovalThreshold($run);

        $stats = null;

        try {
            DB::transaction(function () use ($run, $draw, $after, $size, $currency, $safety, $threshold, &$stats): void {
                $result = $this->settlement->publishedResultFor($draw);

                $stats = $this->settlement->settleBetRange(
                    $draw,
                    $result,
                    $after,
                    $size,
                    $safety,
                    $currency,
                    $threshold,
                    true,
                );

                $cursor = max($after, (int) $stats['last_bet_id']);

                if ($cursor > (int) $run->max_bet_id) {
                    throw new \RuntimeException(sprintf(
                        'Settlement run %d advanced to bet %d, past the frozen participant bound %d.',
                        (int) $run->getKey(),
                        $cursor,
                        (int) $run->max_bet_id,
                    ));
                }

                $this->persistProgress($run, $cursor, $stats);
            });
        } catch (\Throwable $e) {
            // Recorded OUTSIDE the rolled-back transaction: the money is gone,
            // but the operator still needs to know why the run stopped.
            $run->newQuery()->whereKey($run->getKey())->update([
                'failure_reason' => mb_substr($e->getMessage(), 0, 500),
                'last_chunk_at' => now(),
                'updated_at' => now(),
            ]);

            throw $e;
        }

        $run->refresh();

        if (($stats['currency'] ?? null) instanceof Currency) {
            $run->forceFill(['currency' => $stats['currency']->value])->save();
        }

        return $run;
    }

    /**
     * Whether this run still has bets past its cursor.
     */
    public function hasMoreWork(SettlementRun $run): bool
    {
        return $this->remainingBets($run) > 0;
    }

    /**
     * Bets of this run's draw that no chunk has processed yet.
     */
    public function remainingBets(SettlementRun $run): int
    {
        return Bet::query()
            ->where('draw_id', (int) $run->draw_id)
            ->whereNull('deleted_at')
            ->where('id', '>', (int) $run->cursor_bet_id)
            ->count();
    }

    /**
     * Drive the run to completion from wherever it stands, then finalize it.
     *
     * Never moves the draw itself: `finalize()` is the only thing that may, and
     * it refuses while any bet remains past the cursor.
     */
    public function resume(int $drawId): ?SettlementRun
    {
        $run = $this->runFor($drawId);

        if (! $run instanceof SettlementRun) {
            return null;
        }

        if ($run->isComplete()) {
            return $run;
        }

        if ((string) $run->status === SettlementRun::STATUS_ABORTED) {
            $run = $this->reopen($run);
        }

        // Bounded by construction — every chunk either advances the cursor,
        // finalizes, or throws — but the cap keeps a pathological row from
        // spinning a worker forever.
        $guard = max(1, (int) $run->bets_total) + 1000;

        while ($this->hasMoreWork($run) && $guard-- > 0) {
            $run = $this->runChunk($run);
        }

        return $this->finalize($run);
    }

    /**
     * Run a whole draw: open the run, settle every chunk, finalize once.
     */
    public function run(int $drawId, ?int $chunkSize = null, ?int $startedBy = null): SettlementRun
    {
        $run = $this->start($drawId, $chunkSize, $startedBy);

        return $run->isComplete() ? $run : $this->resume($drawId);
    }

    /**
     * Close the run, and only then move the draw to Settled.
     *
     * THE ORDER IS THE GUARANTEE. A draw moved to Settled while a bet is unpaid
     * is unrecoverable in this project — Settled is terminal and nothing
     * reversal-shaped exists — so the proof comes first and the lifecycle change
     * comes last, in one transaction with the run's own completion. If the
     * lifecycle audit cannot be written, the run stays open too: a run that says
     * "completed" over a draw that says "published" is two contradictory records
     * of the same settlement.
     */
    public function finalize(SettlementRun $run): SettlementRun
    {
        $run->refresh();

        if ($run->isComplete()) {
            // Idempotent: an already-finished run may still need its draw moved
            // (a crash between the two writes), and finalizeChunkedSettlement
            // itself returns an already-settled draw untouched.
            $draw = Draw::query()->find((int) $run->draw_id);

            if ($draw instanceof Draw && $this->lifecycle->currentState($draw)->isSettled() === false) {
                $this->settlement->finalizeChunkedSettlement($draw, $run->totals(), $run);
            }

            return $run;
        }

        if (DB::transactionLevel() > 0) {
            throw new \RuntimeException('Finalizing a settlement run must own its transaction.');
        }

        $this->assertPinned($run);

        $remaining = $this->remainingBets($run);

        if ($remaining > 0) {
            throw new \RuntimeException(sprintf(
                'Settlement run %d cannot finalize: %d bet(s) remain past cursor %d. '
                .'Run the remaining chunks first; a draw is never moved to Settled with unpaid bets.',
                (int) $run->getKey(),
                $remaining,
                (int) $run->cursor_bet_id,
            ));
        }

        // Cursor and counters are not proof on their own: they can be written by
        // anything. These four checks read the rows the run claims to describe.
        $this->assertCountersMatchRows($run);
        $this->assertParticipantsUnchanged($run);
        $this->assertResultUnchanged($run);
        $this->assertOutcomesIntact($run);

        $draw = Draw::query()->find((int) $run->draw_id);

        if (! $draw instanceof Draw) {
            throw new \RuntimeException(sprintf(
                'Draw %d does not exist; settlement run %d cannot be finalized.',
                (int) $run->draw_id,
                (int) $run->getKey(),
            ));
        }

        DB::transaction(function () use ($run, $draw): void {
            $this->settlement->finalizeChunkedSettlement($draw, $run->totals(), $run);

            $run->forceFill([
                'status' => SettlementRun::STATUS_COMPLETED,
                'completed_at' => now(),
                'failure_reason' => null,
            ])->save();
        });

        Log::info('settlement.run_completed', [
            'draw_id' => (int) $run->draw_id,
            'run_id' => (int) $run->getKey(),
            'bets_settled' => (int) $run->bets_settled,
            'total_prize' => (string) $run->total_prize,
        ]);

        return $run->refresh();
    }

    /**
     * Refuse a run that cannot prove what it was opened against.
     */
    private function assertPinned(SettlementRun $run): void
    {
        if (! is_string($run->result_fingerprint) || $run->result_fingerprint === ''
            || ! is_string($run->participants_fingerprint) || $run->participants_fingerprint === '') {
            throw new \RuntimeException(sprintf(
                'Settlement run %d has no pinned result/participant proof; it cannot be resumed, chunked or finalized.',
                (int) $run->getKey(),
            ));
        }
    }

    private function assertParticipantsUnchanged(SettlementRun $run): void
    {
        $drawId = (int) $run->draw_id;
        $frozen = $this->freezeParticipants($drawId);

        // Every participant must still CARRY selections. A bet whose items were
        // deleted is not settled by being walked past: it has nothing to evaluate,
        // so it would be counted as processed while its outcome was never
        // decided. `bets_settled` would then include a slip nobody settled.
        $withItems = DB::table('bet_items')
            ->join('bets', 'bets.id', '=', 'bet_items.bet_id')
            ->where('bets.draw_id', $drawId)
            ->whereNull('bets.deleted_at')
            ->whereNull('bet_items.deleted_at')
            ->distinct()
            ->count('bets.id');

        if ($withItems !== $frozen['count']) {
            throw new \RuntimeException(sprintf(
                'Draw %d has %d participant(s) but only %d carry selections; refusing to settle a bet with no outcome.',
                $drawId,
                $frozen['count'],
                $withItems,
            ));
        }

        if (! hash_equals((string) $run->participants_fingerprint, $frozen['fingerprint'])) {
            throw new \RuntimeException(sprintf(
                'The participant set of draw %d changed since settlement run %d was opened '
                .'(a bet added, removed, emptied or edited). Refusing: a chunked run may only settle the set it froze.',
                (int) $run->draw_id,
                (int) $run->getKey(),
            ));
        }
    }

    private function assertResultUnchanged(SettlementRun $run): void
    {
        if (! hash_equals((string) $run->result_fingerprint, $this->resultFingerprint((int) $run->draw_id))) {
            throw new \RuntimeException(sprintf(
                'The published result of draw %d changed since settlement run %d was opened; refusing to pay against different numbers.',
                (int) $run->draw_id,
                (int) $run->getKey(),
            ));
        }
    }

    /**
     * The run's own numbers, checked against the rows they claim to describe.
     */
    private function assertCountersMatchRows(SettlementRun $run): void
    {
        $drawId = (int) $run->draw_id;

        $maxBetId = (int) Bet::query()->where('draw_id', $drawId)->whereNull('deleted_at')->max('id');

        if ((int) $run->cursor_bet_id !== $maxBetId) {
            throw new \RuntimeException(sprintf(
                'Settlement run %d claims cursor %d but the last participant of draw %d is bet %d; the run is not finished.',
                (int) $run->getKey(),
                (int) $run->cursor_bet_id,
                $drawId,
                $maxBetId,
            ));
        }

        if ((int) $run->bets_settled !== (int) $run->bets_total) {
            throw new \RuntimeException(sprintf(
                'Settlement run %d claims %d of %d bets settled; a partially settled run cannot be finalized.',
                (int) $run->getKey(),
                (int) $run->bets_settled,
                (int) $run->bets_total,
            ));
        }

        $expected = $this->settledTotals($drawId, (int) $run->cursor_bet_id);

        if ((int) $expected['bets_settled'] !== (int) $run->bets_settled) {
            throw new \RuntimeException(sprintf(
                'Settlement run %d reports %d settled bets but only %d bets of draw %d carry settled outcomes.',
                (int) $run->getKey(),
                (int) $run->bets_settled,
                (int) $expected['bets_settled'],
                $drawId,
            ));
        }
    }

    /**
     * The outcomes the run produced must still be the outcomes in the rows, and
     * the payouts must still cover them.
     *
     * This is what makes a doctored `payouts` row — or a deleted one — stop a
     * finalization instead of disappearing into a settled draw.
     */
    private function assertOutcomesIntact(SettlementRun $run): void
    {
        if (! is_string($run->outcomes_fingerprint) || $run->outcomes_fingerprint === '') {
            throw new \RuntimeException(sprintf(
                'Settlement run %d has no recorded outcome proof; it cannot be finalized.',
                (int) $run->getKey(),
            ));
        }

        $drawId = (int) $run->draw_id;

        if (! hash_equals((string) $run->outcomes_fingerprint, $this->outcomesFingerprint($drawId))) {
            throw new \RuntimeException(sprintf(
                'The settled outcomes of draw %d no longer match what settlement run %d recorded.',
                $drawId,
                (int) $run->getKey(),
            ));
        }

        $obligation = $this->settledTotals($drawId, (int) $run->cursor_bet_id)['prize'];
        $paid = (string) Payout::query()->where('draw_id', $drawId)->sum('amount');

        if (bccomp($obligation, bcadd($paid, '0.00', 2), 2) !== 0) {
            throw new \RuntimeException(sprintf(
                'Draw %d owes %s in prizes but its payout rows total %s; a missing or altered obligation blocks completion.',
                $drawId,
                $obligation,
                bcadd($paid, '0.00', 2),
            ));
        }
    }

    /**
     * The safety mode this chunk actually pays under.
     *
     * THE PIN CAN ONLY TIGHTEN. A run opened under DRY_RUN never starts paying
     * because config was flipped to LIVE mid-run, and a run opened under LIVE
     * stops paying the moment the estate is switched to DRY_RUN or DISABLED.
     * Both directions are the same rule: the stricter of the two wins.
     */
    private function effectiveSafetyMode(SettlementRun $run): string
    {
        $pinned = (string) ($run->safety_mode ?? RealPrizeSettlementService::SAFETY_DISABLED);
        $current = $this->settlement->safetyMode();

        $rank = [
            RealPrizeSettlementService::SAFETY_DISABLED => 0,
            RealPrizeSettlementService::SAFETY_DRY_RUN => 1,
            RealPrizeSettlementService::SAFETY_LIVE => 2,
        ];

        $pinnedRank = $rank[$pinned] ?? 0;
        $currentRank = $rank[$current] ?? 0;

        return $pinnedRank <= $currentRank ? $pinned : $current;
    }

    /**
     * The approval threshold this chunk pays under: the stricter (lower) of the
     * pinned value and the current one.
     */
    private function effectiveApprovalThreshold(SettlementRun $run): ?string
    {
        $current = $this->settlement->thresholdAsString(
            (string) config('finance.prize_payout.auto_approve_below', '1000000.00'),
        );

        if ($run->approval_threshold === null) {
            return $current;
        }

        $pinned = $this->settlement->thresholdAsString((string) $run->approval_threshold);

        return bccomp($pinned, $current, 2) <= 0 ? $pinned : $current;
    }

    /**
     * Freeze the participant set and its selection inputs.
     *
     * @return array{count: int, max_id: int, fingerprint: string}
     */
    private function freezeParticipants(int $drawId): array
    {
        $bets = DB::table('bets')
            ->where('draw_id', $drawId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'type', 'currency', 'stake_amount']);

        $items = DB::table('bet_items')
            ->join('bets', 'bets.id', '=', 'bet_items.bet_id')
            ->where('bets.draw_id', $drawId)
            ->whereNull('bets.deleted_at')
            ->whereNull('bet_items.deleted_at')
            ->orderBy('bet_items.id')
            ->get(['bet_items.bet_id', 'bet_items.number', 'bet_items.amount', 'bet_items.payout_multiplier']);

        $canonical = [
            'bets' => $bets->map(static fn ($bet): array => [
                (int) $bet->id,
                (string) $bet->type,
                (string) $bet->currency,
                (string) $bet->stake_amount,
            ])->all(),
            'items' => $items->map(static fn ($item): array => [
                (int) $item->bet_id,
                (string) $item->number,
                (string) $item->amount,
                (string) $item->payout_multiplier,
            ])->all(),
        ];

        return [
            'count' => $bets->count(),
            'max_id' => (int) ($bets->max('id') ?? 0),
            'fingerprint' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Hash of the published result row, so a result edited mid-run is refused.
     */
    private function resultFingerprint(int $drawId): string
    {
        $row = DB::table('draw_results')->where('draw_id', $drawId)->first();

        if ($row === null) {
            return hash('sha256', 'absent');
        }

        $attributes = (array) $row;
        unset($attributes['updated_at']);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    /**
     * Hash of the OUTCOMES written for this draw: per item, the winner flag and
     * the actual payout. Recorded at every checkpoint and re-proved at
     * finalization.
     */
    private function outcomesFingerprint(int $drawId): string
    {
        $items = DB::table('bet_items')
            ->join('bets', 'bets.id', '=', 'bet_items.bet_id')
            ->where('bets.draw_id', $drawId)
            ->whereNull('bets.deleted_at')
            ->orderBy('bet_items.id')
            ->get(['bet_items.id', 'bet_items.bet_id', 'bet_items.is_winner', 'bet_items.actual_payout']);

        return hash('sha256', json_encode($items->map(static fn ($item): array => [
            (int) $item->id,
            (int) $item->bet_id,
            (bool) $item->is_winner,
            (string) $item->actual_payout,
        ])->all(), JSON_THROW_ON_ERROR));
    }

    /**
     * What the processed range actually contains.
     *
     * @return array{bets_settled: int, selections: int, winning_selections: int, stake: string, prize: string}
     */
    private function settledTotals(int $drawId, int $cursor): array
    {
        $items = DB::table('bet_items')
            ->join('bets', 'bets.id', '=', 'bet_items.bet_id')
            ->where('bets.draw_id', $drawId)
            ->whereNull('bets.deleted_at')
            ->whereNull('bet_items.deleted_at')
            ->where('bets.id', '<=', $cursor)
            ->selectRaw(
                "COUNT(*) AS selections, COALESCE(SUM(bet_items.amount), 0) AS stake_total, "
                ."COALESCE(SUM(CASE WHEN bet_items.is_winner = 1 THEN 1 ELSE 0 END), 0) AS winning_selections, "
                ."COALESCE(SUM(CASE WHEN bet_items.is_winner = 1 THEN bet_items.actual_payout ELSE 0 END), 0) AS prize_total",
            )
            ->first();

        $betsSettled = DB::table('bet_items')
            ->join('bets', 'bets.id', '=', 'bet_items.bet_id')
            ->where('bets.draw_id', $drawId)
            ->whereNull('bets.deleted_at')
            ->whereNull('bet_items.deleted_at')
            ->where('bets.id', '<=', $cursor)
            ->distinct()
            ->count('bets.id');

        return [
            'bets_settled' => (int) $betsSettled,
            'selections' => (int) ($items->selections ?? 0),
            'winning_selections' => (int) ($items->winning_selections ?? 0),
            'stake' => bcadd((string) ($items->stake_total ?? '0'), '0', 2),
            'prize' => bcadd((string) ($items->prize_total ?? '0'), '0', 2),
        ];
    }

    /**
     * Write the checkpoint for a chunk: cursor, counters, outcome proof.
     *
     * Called INSIDE the chunk's transaction, so a checkpoint that cannot be
     * written takes the chunk's payouts and wallet credits with it.
     *
     * TOTALS ARE RECOMPUTED, NOT ACCUMULATED. The run row is the number an
     * operator reconciles against `payouts`, so it is derived from the rows that
     * actually exist in the processed range. Summing deltas in PHP would
     * double-count a re-run chunk — "idempotent but lying" — and would drift from
     * the payouts table it is supposed to describe.
     *
     * @param  array<string, mixed>  $stats
     */
    private function persistProgress(SettlementRun $run, int $cursor, array $stats): void
    {
        $drawId = (int) $run->draw_id;
        $settled = $this->settledTotals($drawId, $cursor);

        $payouts = Payout::query()->where('draw_id', $drawId)
            ->selectRaw('COUNT(*) AS payouts, COALESCE(SUM(amount), 0) AS prize_total')
            ->first();

        $run->forceFill([
            'cursor_bet_id' => $cursor,
            'bets_settled' => $settled['bets_settled'],
            'selections_evaluated' => $settled['selections'],
            'selections_written' => $settled['selections'],
            'winning_selections' => $settled['winning_selections'],
            'payouts_created' => (int) ($payouts->payouts ?? 0),
            'total_stake' => $settled['stake'],
            'total_prize' => bcadd((string) ($payouts->prize_total ?? '0'), '0', 2),
            'outcomes_fingerprint' => $this->outcomesFingerprint($drawId),
            'chunks_processed' => (int) $run->chunks_processed + 1,
            'last_chunk_at' => now(),
            'failure_reason' => null,
        ])->save();
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
