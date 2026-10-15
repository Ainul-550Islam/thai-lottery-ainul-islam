<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\DTOs\DrawResultData;
use App\DTOs\SettlementResult;
use App\DTOs\SettlementSelectionResult;
use App\Enums\AuditAction;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Enums\DrawLifecycleState;
use App\Enums\FinancialTransactionType;
use App\Enums\MarketResultType;
use App\Enums\PayoutStatus;
use App\Enums\RiskLevel;
use App\Exceptions\DrawLifecycleException;
use App\Exceptions\SettlementSimulationException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\Payout;
use App\Models\SettlementRun;
use App\Models\User;
use App\Models\WinningNumber;
use App\Services\Betting\BetPurchaseWalletService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;

/**
 * REAL (monetary) prize settlement of one published draw.
 *
 * THE MONEY TWIN OF DrawSettlementSimulationService
 * The simulation service proves that settlement logic is reproducible without
 * touching money; this service is the one that actually pays. It reuses the
 * same evaluation pipeline — published result → DrawResultData → per selection
 * SettlementSelectionResult — and then, inside ONE database transaction:
 *
 * 1. RE-VERIFIES the published result against winning_numbers row by row.
 * 2. WRITES each selection's settlement columns (is_winner, actual_payout,
 *    payout_multiplier) — the same three the simulation writes.
 * 3. CREATES one payouts row per winning BET and credits the winner's wallet
 *    through WalletService::credit(FinancialTransactionType::Payout), which
 *    posts the balanced double entry (prize expense ⇄ player liability) and
 *    moves the balance atomically.
 * 4. MARKS each bet Won/Lost, links winning bets to their payout via
 *    bets.payout_id, and stamps won_at.
 * 5. TRANSITIONS the draw to its settled state through the lifecycle service.
 * 6. RECORDS the run in audit_logs.
 *
 * IDEMPOTENCY CONTRACT
 * settle() on an already-settled draw computes nothing new: it re-reads the
 * stored settlement, verifies the stored selection columns still agree with
 * the evaluation of the published result (any drift is reported loudly, never
 * overwritten), and returns a SettlementResult with alreadySettled=true,
 * selectionsWritten=0, payoutsCreated=0. Payout references are deterministic
 * per (draw, bet), so even a crash-and-retry out-of-sequence cannot create a
 * second payout for the same bet; the wallet credit is additionally claimed
 * with a per-bet idempotency key, so a retried credit returns the original
 * financial transaction instead of double-crediting.
 *
 * CURRENCY
 * A draw's bets are expected to share one currency; a mixed-currency draw
 * produces a meaningless payout total and is refused rather than summed.
 */
class RealPrizeSettlementService
{
    /**
     * The only mode this service has. A constant, so nothing can change it.
     */
    public const MODE = 'monetary';

    /** Safety switch states (config finance.prize_payout.safety_mode). */
    public const SAFETY_DISABLED = 'DISABLED';
    public const SAFETY_DRY_RUN = 'DRY_RUN';
    public const SAFETY_LIVE = 'LIVE';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly DrawLifecycleService $lifecycle,
        private readonly DrawResultValidator $validator,
        private readonly SelectionSettlementResolver $selections,
        private readonly BetPurchaseWalletService $wallets,
        private readonly WalletService $walletService,
    ) {}

    /**
     * Settle one published draw, paying every winning bet exactly once.
     *
     * Takes a draw id and nothing else, on purpose: the caller cannot smuggle
     * in a context that would make a repeat run behave differently.
     *
     * @throws DrawLifecycleException
     * @throws SettlementSimulationException
     * @throws \App\Exceptions\FinancialException
     */
    public function settle(int $drawId): SettlementResult
    {
        if (DB::transactionLevel() > 0) {
            // The rollback guarantee must belong to this run. Inside a caller's
            // transaction a failure here could be swallowed by an outer catch and
            // the partial writes — including paid-out money — committed anyway.
            throw SettlementSimulationException::alreadyRunning($drawId, DB::transactionLevel());
        }

        return DB::transaction(function () use ($drawId): SettlementResult {
            $draw = $this->lifecycle->lockForUpdate($drawId);
            $stateBefore = $this->lifecycle->currentState($draw);

            if ($stateBefore->isSettled()) {
                // Already settled: read back, write nothing, pay nothing.
                return $this->replayStoredSettlement($draw, $stateBefore);
            }

            // ── REFUSE WHILE A CHUNKED RUN IS PART-WAY THROUGH THIS DRAW.
            //
            // ChunkedSettlementOrchestrator settles a draw in slices and does
            // NOT move the draw to Settled until the last chunk. So during a
            // chunked run the draw is still ResultPublished and this method —
            // which until now knew nothing about settlement_runs — would happily
            // start a SECOND, monolithic settlement alongside it.
            //
            // The overlap is money-safe: both paths collide on the same
            // deterministic per-(draw, bet) payout reference and the same
            // per-bet wallet idempotency key, so no bet is paid twice. It is not
            // REPORT-safe, and that is not a lesser problem. Each run would
            // report its own total, both would look authoritative, and a
            // reported prize total that does not match what was paid is exactly
            // the number an operator uses to decide whether to escalate. Two
            // settlements racing over one draw would also fight for the same row
            // locks for the duration of the larger one.
            //
            // The check is INSIDE this transaction and reads the run row
            // directly rather than through the orchestrator, because
            // ChunkedSettlementOrchestrator depends on this service and
            // injecting it back would be a circular dependency. The coupling
            // that remains is one table read, and it buys the guarantee that two
            // settlement runs cannot overlap on one draw.
            if ($this->chunkedRunInFlight($drawId)) {
                throw SettlementSimulationException::alreadyRunning(
                    $drawId,
                    DB::transactionLevel(),
                    [
                        'reason' => 'a chunked settlement run is in flight for this draw',
                        'resolution' => 'let the chunked run finish, or resume it with '
                            .'php artisan lottery:settle-draw-chunked --draw='.$drawId.' --resume, '
                            .'before settling this draw monolithically',
                    ],
                );
            }

            $this->lifecycle->assertCanSettle($draw, ['stage' => 'settlement', 'mode' => self::MODE]);

            return $this->performSettlement($draw, $stateBefore);
        });
    }

    /**
     * The chunked settlement run for this draw that has not yet completed, if any.
     *
     * Deliberately counts BOTH `open` and `aborted` runs. An aborted run is a
     * resumable run — its cursor and totals are exactly what the last committed
     * chunk left behind — so treating it as "not in flight" would let the
     * monolithic path settle alongside money that a resume is still going to pay.
     * That is the same overlap, only with a paused second participant.
     */
    public function inFlightChunkedRun(int $drawId): ?SettlementRun
    {
        return SettlementRun::query()
            ->where('draw_id', $drawId)
            ->whereIn('status', [SettlementRun::STATUS_OPEN, SettlementRun::STATUS_ABORTED])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Whether a chunked settlement run for this draw has not yet completed.
     *
     * PUBLIC, and not merely because the guard above calls it. The monolithic
     * queued settlement (ProcessPrizeSettlementJob) needs the same answer BEFORE
     * it calls settle(), so that it can stand aside instead of being failed by
     * the refusal. A refusal that a caller cannot see coming is a refusal that
     * turns into a permanently failed job.
     */
    public function chunkedRunInFlight(int $drawId): bool
    {
        return $this->inFlightChunkedRun($drawId) !== null;
    }

    /**
     * Rebuild the outcome of an already settled draw, writing nothing.
     *
     * @throws DrawLifecycleException
     * @throws SettlementSimulationException
     */
    public function storedSettlement(int $drawId): SettlementResult
    {
        return DB::transaction(function () use ($drawId): SettlementResult {
            $draw = $this->lifecycle->lockForUpdate($drawId);
            $state = $this->lifecycle->currentState($draw);

            if (! $state->isSettled()) {
                throw SettlementSimulationException::selectionUnreadable(
                    0,
                    sprintf(
                        'draw %d is in state %s; a stored settlement exists only after settlement',
                        $drawId,
                        $state->value,
                    ),
                    ['draw_id' => $drawId],
                );
            }

            return $this->replayStoredSettlement($draw, $state);
        });
    }

    /**
     * The published result of the draw, integrity-checked.
     *
     * Reads the same rows DrawSettlementSimulationService reads: the
     * draw_results raw numbers validated into a DrawResultData, then every
     * winning_numbers row confirmed to store exactly the derived per-market
     * number of its prize tier, in both directions. Settling money against a
     * tampered result is the worst failure this system can have, so the check
     * is identical here; any mismatch throws before a single baht moves.
     *
     * @throws DrawLifecycleException
     * @throws SettlementSimulationException
     */
    public function publishedResultFor(Draw $draw): DrawResultData
    {
        $drawId = (int) $draw->getKey();

        $result = DrawResult::query()->where('draw_id', $drawId)->first();

        if (! $result instanceof DrawResult) {
            throw DrawLifecycleException::resultMissing($drawId, $this->lifecycle->currentState($draw));
        }

        $metadata = is_array($result->metadata) ? $result->metadata : [];
        $bottomTwoKey = $this->validator->bottomTwoMetadataKey();

        $data = $this->validator->validate([
            'first_prize' => $result->first_prize,
            'bottom_two' => $metadata[$bottomTwoKey] ?? null,
        ]);

        // $data was produced from the result row the publication service wrote
        // atomically with winning_numbers; confirm nothing drifted since.
        $this->assertResultIntegrity($drawId, $data);

        return $data;
    }

    /**
     * Assert a draw can be settled in CHUNKS, and return its validated result.
     *
     * Called ONCE when a chunked run is opened, for two reasons that the chunk
     * loop itself cannot provide:
     *
     *   1. THE LIFECYCLE IS CHECKED EXACTLY ONCE, AT THE START, AND THE DRAW IS
     *      NOT MOVED. settle() transitions to Settled at the end of its single
     *      transaction, so there is no window in which the draw is half settled.
     *      A chunked run has exactly that window: chunks 1..n-1 have paid money
     *      while the draw is still ResultPublished. Moving it at the end — and
     *      only at the end — is what makes the window meaningful, because
     *      ResultPublished + a settlement_runs row is the unambiguous, resumable
     *      signature of "partially settled".
     *
     *   2. THE RESULT IS VALIDATED BEFORE ANY MONEY MOVES. publishedResultFor()
     *      re-reads the published numbers and confirms every winning_numbers row
     *      still agrees with them. Doing this once up front means a tampered
     *      result is refused before the first chunk rather than after the
     *      ninetieth; each chunk re-validates anyway, but failing early is the
     *      difference between an aborted run and a run that paid 90,000 slips
     *      against numbers it should never have touched.
     *
     * @throws DrawLifecycleException
     * @throws SettlementSimulationException
     */
    public function assertChunkable(Draw $draw): DrawResultData
    {
        return DB::transaction(function () use ($draw): DrawResultData {
            $drawId = (int) $draw->getKey();

            if (DB::transactionLevel() > 1) {
                throw SettlementSimulationException::alreadyRunning($drawId, DB::transactionLevel());
            }

            $locked = $this->lifecycle->lockForUpdate($drawId);
            $state = $this->lifecycle->currentState($locked);

            if ($state->isSettled()) {
                throw SettlementSimulationException::selectionUnreadable(
                    0,
                    sprintf('draw %d is already settled; a chunked run would pay nothing', $drawId),
                    ['draw_id' => $drawId],
                );
            }

            $this->lifecycle->assertCanSettle($locked, [
                'stage' => 'settlement',
                'mode' => self::MODE,
                'chunked' => true,
            ]);

            return $this->publishedResultFor($locked);
        });
    }

    /**
     * Settle ONE CHUNK: every bet of the draw with id greater than $afterBetId,
     * up to $limit of them, inside a transaction of its own.
     *
     * WHY THIS IS A METHOD ON THIS SERVICE AND NOT IN THE ORCHESTRATOR
     * Because "how a winning bet is paid" must have exactly ONE definition. The
     * chunked path is not a second settlement implementation — it is the same
     * one, entered in slices. This method calls the same payWinningBet(),
     * writeSelection() and writeBet() that performSettlement() calls, in the
     * same order, with the same deterministic per-(draw,bet) payout reference
     * and the same per-bet wallet idempotency key.
     *
     * That is what makes a chunk safe to RETRY. If a chunk's transaction fails
     * after paying 300 of its 500 bets, nothing about the failed chunk survives
     * — it rolls back whole — and re-running it pays the same 500 bets and hits
     * the same unique payout references. The unique index is the guarantee;
     * chunking does not weaken it.
     *
     * WHAT IT DELIBERATELY DOES NOT DO
     *   - it does not transition the draw (finalizeChunkedSettlement does that,
     *     once, when every bet has been processed);
     *   - it does not build a SettlementResult (that DTO carries every selection
     *     in memory, which is the cost chunking exists to avoid — it returns
     *     counters instead);
     *   - it does not touch settlement_runs (progress is the orchestrator's job,
     *     so this money service stays free of run bookkeeping).
     *
     * $limit is clamped to at least 1. A limit of 0 would return "exhausted"
     * with bets remaining, and the orchestrator would conclude the draw was
     * finished — a silent under-settlement, which is the one failure mode that
     * pays some players and strands others.
     *
     * @return array{
     *     bets_seen: int,
     *     bets_settled: int,
     *     selections_evaluated: int,
     *     selections_written: int,
     *     winning_selections: int,
     *     payouts_created: int,
     *     total_stake_delta: string,
     *     total_prize_delta: string,
     *     currency: Currency|null,
     *     last_bet_id: int,
     *     exhausted: bool
     * }
     *
     * @throws DrawLifecycleException
     * @throws SettlementSimulationException
     * @throws \App\Exceptions\FinancialException
     */
    public function settleBetRange(
        Draw $draw,
        DrawResultData $result,
        int $afterBetId,
        int $limit,
        string $safetyMode,
        ?Currency $currencySoFar = null,
    ): array {
        $drawId = (int) $draw->getKey();
        $limit = max(1, $limit);

        if (DB::transactionLevel() > 0) {
            // Same rule as settle(): the rollback guarantee must belong to this
            // chunk. Inside a caller's transaction, a failure here could be
            // swallowed by an outer catch and the partial chunk — including
            // paid-out money — committed anyway.
            throw SettlementSimulationException::alreadyRunning($drawId, DB::transactionLevel());
        }

        return DB::transaction(function () use ($draw, $drawId, $result, $afterBetId, $limit, $safetyMode, $currencySoFar): array {
            $bets = $this->lockedBetsAfter($drawId, $afterBetId, $limit);

            $stats = [
                'bets_seen' => $bets->count(),
                'bets_settled' => 0,
                'selections_evaluated' => 0,
                'selections_written' => 0,
                'winning_selections' => 0,
                'payouts_created' => 0,
                'total_stake_delta' => '0.00',
                'total_prize_delta' => '0.00',
                // Seeded from the previous chunk so the one-currency rule is
                // enforced across the whole run and not merely within a slice.
                'currency' => $currencySoFar,
                'last_bet_id' => $afterBetId,
                'exhausted' => $bets->count() < $limit,
            ];

            foreach ($bets as $bet) {
                $betPrize = '0.00';
                $betHasWinner = false;
                $betTouched = false;

                // Currency coherence, through the SAME helper the monolithic
                // path uses, so a mixed-currency draw is refused here for the
                // same reason and with the same message. Within a chunk this
                // accumulates; ACROSS chunks it is carried by the run row's
                // recorded currency, which the orchestrator seeds from the
                // previous chunk.
                $stats['currency'] = $this->assertSingleCurrency($drawId, $stats['currency'], $bet);

                foreach ($this->lockedItems((int) $bet->getKey()) as $item) {
                    $record = $this->selections->resolve($item, $bet, $result);

                    $this->writeSelection($item, $record);

                    $stats['selections_evaluated']++;
                    $stats['selections_written']++;
                    $betTouched = true;

                    $stats['total_stake_delta'] = bcadd($stats['total_stake_delta'], $record->stake, 2);
                    $stats['total_prize_delta'] = bcadd($stats['total_prize_delta'], $record->simulatedPrize, 2);
                    $betPrize = bcadd($betPrize, $record->simulatedPrize, 2);

                    if ($record->isWinner()) {
                        $betHasWinner = true;
                        $stats['winning_selections']++;
                    }

                    // The DTO is deliberately dropped here. In the monolithic
                    // path every one of these is retained in $records and handed
                    // back inside SettlementResult; at 100,000+ slips that list
                    // is the memory failure. The row is written above, which is
                    // the durable record that matters.
                    unset($record);
                }

                if (! $betTouched) {
                    $stats['last_bet_id'] = (int) $bet->getKey();

                    continue;
                }

                $stats['bets_settled']++;

                if ($betHasWinner && bccomp($betPrize, '0.00', 2) > 0) {
                    $currency = $stats['currency'] instanceof Currency ? $stats['currency'] : $this->defaultCurrency();

                    $payout = $this->payWinningBet($bet, $betPrize, $currency, $safetyMode);

                    $this->writeBet($bet, true, $betPrize, $payout);
                    $stats['payouts_created']++;
                } else {
                    $this->writeBet($bet, $betHasWinner, $betHasWinner ? $betPrize : '0.00', null);
                }

                $stats['last_bet_id'] = (int) $bet->getKey();
            }

            return $stats;
        });
    }

    /**
     * Transition a chunked run's draw to Settled. Called ONCE, after the
     * orchestrator has confirmed there are no bets past the cursor.
     *
     * Idempotent by construction: an already-settled draw is returned as-is, so
     * a finalize that runs twice (a retried job, an operator re-running the
     * command) cannot move a terminal state a second time. That matters because
     * Settled is terminal in this project and nothing reversal-shaped is
     * implemented — moving it twice is not "harmless".
     *
     * @param  array<string, mixed>  $totals  the run's recorded totals, for the audit trail
     *
     * @throws DrawLifecycleException
     */
    public function finalizeChunkedSettlement(Draw $draw, array $totals = []): Draw
    {
        return DB::transaction(function () use ($draw, $totals): Draw {
            $locked = $this->lifecycle->lockForUpdate((int) $draw->getKey());
            $state = $this->lifecycle->currentState($locked);

            if ($state->isSettled()) {
                return $locked;
            }

            $this->lifecycle->assertCanSettle($locked, [
                'stage' => 'settlement',
                'mode' => self::MODE,
                'chunked' => true,
            ]);

            return $this->lifecycle->markSettled($locked, [
                'stage' => 'settlement',
                'mode' => self::MODE,
                'chunked' => true,
                'selections' => $totals['selections_written'] ?? null,
                'payouts_created' => $totals['payouts_created'] ?? null,
                'bets_settled' => $totals['bets_settled'] ?? null,
                'total_prize' => $totals['total_prize'] ?? null,
            ]);
        });
    }

    /**
     * The next slice of a draw's bets, locked for update, in a stable order.
     *
     * KEYSET PAGINATION, NOT OFFSET. `id > $afterBetId` walks the primary key
     * index and touches only the rows it returns. An OFFSET would make chunk N
     * scan and discard every row before it — the hundredth chunk would read
     * 100,000 rows to lock 500, which is the same cost as not chunking at all
     * while looking like progress.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Bet>
     */
    private function lockedBetsAfter(int $drawId, int $afterBetId, int $limit)
    {
        return Bet::query()
            ->with(['ticket', 'user'])
            ->where('draw_id', $drawId)
            ->whereNull('deleted_at')
            ->where('id', '>', $afterBetId)
            ->orderBy('id')
            ->limit($limit)
            ->lockForUpdate()
            ->get();
    }

    /**
     * Do the settlement. Called with the draw already locked, inside the
     * transaction. If anything throws after the first wallet write, every
     * payout, credit, selection write and the draw transition roll away
     * together — money never lands on one side only.
     *
     * @throws DrawLifecycleException
     * @throws SettlementSimulationException
     * @throws \App\Exceptions\FinancialException
     */
    private function performSettlement(Draw $draw, DrawLifecycleState $stateBefore): SettlementResult
    {
        $drawId = (int) $draw->getKey();
        $result = $this->publishedResultFor($draw);
        $safetyMode = $this->safetyMode();

        $records = [];
        $selectionsWritten = 0;
        $betsUpdated = 0;
        $winners = 0;
        $payoutsCreated = 0;
        $totalStake = '0.00';
        $totalPrize = '0.00';
        $currency = null;

        foreach ($this->lockedBets($drawId) as $bet) {
            $betPrize = '0.00';
            $betHasWinner = false;
            $betTouched = false;

            $currency = $this->assertSingleCurrency($drawId, $currency, $bet);

            foreach ($this->lockedItems((int) $bet->getKey()) as $item) {
                $record = $this->selections->resolve($item, $bet, $result);

                $this->writeSelection($item, $record);

                $records[] = $record;
                $selectionsWritten++;
                $betTouched = true;

                $totalStake = bcadd($totalStake, $record->stake, 2);
                $totalPrize = bcadd($totalPrize, $record->simulatedPrize, 2);
                $betPrize = bcadd($betPrize, $record->simulatedPrize, 2);

                if ($record->isWinner()) {
                    $betHasWinner = true;
                    $winners++;
                }
            }

            if (! $betTouched) {
                continue;
            }

            $betsUpdated++;

            if ($betHasWinner && bccomp($betPrize, '0.00', 2) > 0) {
                $payout = $this->payWinningBet($bet, $betPrize, $currency, $safetyMode);

                $this->writeBet($bet, true, $betPrize, $payout);
                $payoutsCreated++;
            } else {
                $this->writeBet($bet, $betHasWinner, $betHasWinner ? $betPrize : '0.00', null);
            }
        }

        $draw = $this->lifecycle->markSettled($draw, [
            'stage' => 'settlement',
            'mode' => self::MODE,
            'selections' => $selectionsWritten,
            'payouts_created' => $payoutsCreated,
        ]);

        $stateAfter = $this->lifecycle->currentState($draw);

        $settlement = new SettlementResult(
            drawId: $drawId,
            drawNumber: (string) $draw->draw_number,
            stateBefore: $stateBefore,
            stateAfter: $stateAfter,
            firstPrize: $result->firstPrize(),
            bottomTwo: $result->bottomTwo(),
            selections: $records,
            selectionsEvaluated: count($records),
            selectionsWritten: $selectionsWritten,
            betsUpdated: $betsUpdated,
            winningSelections: $winners,
            totalStake: $totalStake,
            totalPrize: $totalPrize,
            currency: ($currency ?? $this->defaultCurrency())->value,
            payoutsCreated: $payoutsCreated,
            totalPayoutAmount: bcadd($totalPrize, '0.00', 2),
            alreadySettled: false,
            mode: self::MODE,
            settledAt: now()->toIso8601String(),
            context: [
                'lock_order' => 'DRAW -> DRAW_RESULT -> BETS -> BET_ITEMS -> WALLETS',
                'wrote_wallet' => $payoutsCreated > 0,
                'wrote_ledger' => $payoutsCreated > 0,
                'wrote_financial_transaction' => $payoutsCreated > 0,
                'wrote_payout' => $payoutsCreated > 0,
            ],
        );

        $this->recordAudit($draw, $settlement);

        return $settlement;
    }

    /**
     * Rebuild the outcome of an already settled draw, writing nothing.
     *
     * Selections are recomputed from the published result and compared against
     * the stored columns — drift is reported, never silently overwritten. The
     * payout figures are read from the payouts table (the money record of
     * truth), so the replay reports what was ACTUALLY paid.
     *
     * @throws DrawLifecycleException
     * @throws SettlementSimulationException
     */
    private function replayStoredSettlement(Draw $draw, DrawLifecycleState $state): SettlementResult
    {
        $drawId = (int) $draw->getKey();
        $result = $this->publishedResultFor($draw);

        $records = [];
        $winners = 0;
        $totalStake = '0.00';
        $totalPrize = '0.00';
        $currency = null;

        foreach ($this->betsFor($drawId) as $bet) {
            $currency = $this->assertSingleCurrency($drawId, $currency, $bet);

            foreach ($this->itemsFor((int) $bet->getKey()) as $item) {
                $record = $this->selections->resolve($item, $bet, $result);

                $this->assertStoredMatchesRecomputed($item, $record);

                $records[] = $record;
                $totalStake = bcadd($totalStake, $record->stake, 2);
                $totalPrize = bcadd($totalPrize, $record->simulatedPrize, 2);

                if ($record->isWinner()) {
                    $winners++;
                }
            }
        }

        $payoutStats = $this->storedPayoutStats($drawId);

        return new SettlementResult(
            drawId: $drawId,
            drawNumber: (string) $draw->draw_number,
            stateBefore: $state,
            stateAfter: $state,
            firstPrize: $result->firstPrize(),
            bottomTwo: $result->bottomTwo(),
            selections: $records,
            selectionsEvaluated: count($records),
            // Zero, because this path wrote nothing at all — it paid nothing.
            selectionsWritten: 0,
            betsUpdated: 0,
            winningSelections: $winners,
            totalStake: $totalStake,
            totalPrize: $totalPrize,
            currency: ($currency ?? $this->defaultCurrency())->value,
            payoutsCreated: 0,
            totalPayoutAmount: $payoutStats['total'],
            alreadySettled: true,
            mode: self::MODE,
            settledAt: $draw->completed_at?->toIso8601String() ?? now()->toIso8601String(),
            context: [
                'source' => 'stored settlement re-read; no row was written and no wallet moved',
                'stored_payout_count' => $payoutStats['count'],
                'wrote_wallet' => false,
                'wrote_ledger' => false,
                'wrote_financial_transaction' => false,
                'wrote_payout' => false,
            ],
        );
    }

    /**
     * Pay one winning bet: create its payout obligation, credit the owner's
     * wallet with the balanced ledger posting, and close the payout out.
     *
     * The payout row is created BEFORE the credit so the credit can reference
     * it, and both live in the same transaction, so a failed credit rolls the
     * payout away too — there is never a payout with no money behind it.
     *
     * @throws \App\Exceptions\FinancialException
     */
    private function payWinningBet(Bet $bet, string $prize, Currency $currency, string $safetyMode): Payout
    {
        $betId = (int) $bet->getKey();
        $drawId = (int) $bet->draw_id;
        $userId = (int) $bet->user_id;

        $needsApproval = $this->requiresApproval($prize);

        $payout = new Payout();
        $payout->fill([
            // Deterministic per (draw, bet): a second payout for the same bet
            // in the same draw dies on the unique index even if every other
            // guard somehow let it through.
            'reference_number' => $this->payoutReference($drawId, $betId),
            'draw_id' => $drawId,
            'bet_id' => $betId,
            'user_id' => $userId,
            'ticket_id' => $bet->ticket_id !== null ? (int) $bet->ticket_id : null,
            'currency' => $currency,
            'amount' => $prize,
            'multiplier' => $bet->multiplier !== null ? (string) $bet->multiplier : null,
            'metadata' => [
                'mode' => self::MODE,
                'draw_id' => $drawId,
                'bet_id' => $betId,
                'source' => 'draw_settlement',
                'safety_mode' => $safetyMode,
                'needs_approval' => $needsApproval,
                'dry_run' => $safetyMode === self::SAFETY_DRY_RUN,
            ],
        ]);
        $payout->status = PayoutStatus::Pending;
        $payout->save();

        // SAFETY GATE — DISABLED / DRY_RUN / high-value awaiting approval:
        // prepare the obligation, NEVER credit the wallet.
        if ($safetyMode !== self::SAFETY_LIVE) {
            $this->recordSafetyAudit($payout, $safetyMode, $prize, 'credit_suppressed');

            return $payout;
        }

        if ($needsApproval) {
            // CALCULATED → PENDING_APPROVAL (never straight to EXECUTED).
            $this->recordSafetyAudit($payout, $safetyMode, $prize, 'pending_approval');

            return $payout;
        }

        $user = $bet->user instanceof User ? $bet->user : User::query()->findOrFail($userId);

        // The winner's wallet for the bet's currency. This is the same
        // resolver the purchase path uses, so a prize lands in the wallet the
        // bet debited from (or its primary-equivalent for that currency).
        $wallet = $this->wallets->resolveWallet($user, $currency);

        $payout->wallet_id = (int) $wallet->getKey();
        $payout->save();

        $transaction = $this->walletService->credit(
            wallet: $wallet,
            amount: Money::fromDatabase($prize, $currency),
            type: FinancialTransactionType::Payout,
            idempotencyKey: $this->payoutIdempotencyKey($drawId, $betId),
            options: [
                'description' => sprintf('Prize payout for bet %s (draw #%d)', $bet->bet_number ?? (string) $betId, $drawId),
                'reference_type' => Payout::class,
                'reference_id' => (int) $payout->getKey(),
                'metadata' => [
                    'payout_id' => (int) $payout->getKey(),
                    'payout_reference' => $payout->reference_number,
                    'draw_id' => $drawId,
                    'bet_id' => $betId,
                    'prize_amount' => $prize,
                    'source' => 'draw_settlement',
                ],
            ],
        );

        $payout->financial_transaction_id = (int) $transaction->getKey();
        $payout->status = PayoutStatus::Completed;
        $payout->processed_at = now();
        $payout->save();

        $this->recordSafetyAudit($payout, $safetyMode, $prize, 'executed_live');

        return $payout;
    }

    /**
     * Deployment safety switch. Anything other than the exact string LIVE
     * refuses wallet credit — no hidden fallback.
     */
    public function safetyMode(): string
    {
        $mode = strtoupper((string) config('finance.prize_payout.safety_mode', self::SAFETY_DISABLED));

        return in_array($mode, [self::SAFETY_DISABLED, self::SAFETY_DRY_RUN, self::SAFETY_LIVE], true)
            ? $mode
            : self::SAFETY_DISABLED;
    }

    /**
     * High-value prizes require four-eyes approval under LIVE mode.
     * auto_approve_below is the exclusive upper bound for automatic completion.
     */
    public function requiresApproval(string $prize): bool
    {
        $threshold = (string) config('finance.prize_payout.auto_approve_below', '1000000.00');

        // At or above threshold → approval. Below → automatic (existing design).
        return bccomp($prize, is_numeric($threshold) || is_string($threshold) ? bcadd($threshold, '0.00', 2) : '1000000.00', 2) >= 0;
    }

    private function recordSafetyAudit(Payout $payout, string $safetyMode, string $prize, string $outcome): void
    {
        if (! class_exists(\App\Models\AuditLog::class)) {
            return;
        }

        \App\Models\AuditLog::create([
            'user_id' => (int) $payout->user_id,
            'action' => \App\Enums\AuditAction::Payout,
            'risk_level' => \App\Enums\RiskLevel::High,
            'auditable_type' => Payout::class,
            'auditable_id' => (int) $payout->getKey(),
            'description' => 'prize_payout_safety_'.$outcome,
            'metadata' => [
                'action_type' => 'prize_payout_safety_'.$outcome,
                'safety_mode' => $safetyMode,
                'payout_reference' => $payout->reference_number,
                'amount' => $prize,
                'draw_id' => (int) $payout->draw_id,
                'bet_id' => (int) $payout->bet_id,
            ],
        ]);
    }

    /**
     * Write the settlement columns of one bet.
     *
     * Assigned directly, not through fill(): bets.status, bets.actual_payout,
     * bets.won_at and bets.payout_id are settlement-owned columns, so even a
     * stray mass assignment elsewhere in the app cannot fake a win.
     */
    private function writeBet(Bet $bet, bool $hasWinner, string $prize, ?Payout $payout): void
    {
        $bet->status = $hasWinner ? BetStatus::Won : BetStatus::Lost;
        $bet->actual_payout = bcadd($prize, '0.00', 2);

        if ($payout instanceof Payout) {
            $bet->payout_id = (int) $payout->getKey();
        }

        if ($hasWinner && $bet->won_at === null) {
            $bet->won_at = now();
        }

        $bet->save();
    }

    /**
     * Write the three settlement columns of one selection. Direct assignment,
     * exactly as the simulation service does: bet_items.is_winner,
     * actual_payout and payout_multiplier are excluded from BetItem::$fillable
     * precisely so only settlement services can set them.
     */
    private function writeSelection(BetItem $item, SettlementSelectionResult $record): void
    {
        $columns = $record->toBetItemColumns();

        $item->is_winner = $columns['is_winner'];
        $item->actual_payout = $columns['actual_payout'];
        $item->payout_multiplier = $columns['payout_multiplier'];

        $item->save();
    }

    /**
     * Refuse when the stored settlement of a replay disagrees with the
     * recomputed one. A stored winner flag or prize that no longer matches the
     * published result means the money record and the rules diverged — the
     * discrepancy is surfaced, never overwritten.
     *
     * @throws SettlementSimulationException
     */
    private function assertStoredMatchesRecomputed(BetItem $item, SettlementSelectionResult $record): void
    {
        $itemId = (int) $item->getKey();

        if ((bool) $item->is_winner !== $record->isWinner()) {
            throw SettlementSimulationException::selectionUnreadable(
                $itemId,
                sprintf(
                    'the stored winner flag (%s) disagrees with the recomputed decision (%s) for market %s; '
                    .'the stored settlement is not reproducible and is reported rather than overwritten',
                    $item->is_winner ? 'true' : 'false',
                    $record->isWinner() ? 'true' : 'false',
                    $record->marketKey,
                ),
            );
        }

        if (bccomp((string) $item->actual_payout, $record->simulatedPrize, 2) !== 0) {
            throw SettlementSimulationException::selectionUnreadable(
                $itemId,
                sprintf(
                    'the stored prize %s disagrees with the recomputed %s for market %s; the stored '
                    .'settlement is not reproducible and is reported rather than overwritten',
                    (string) $item->actual_payout,
                    $record->simulatedPrize,
                    $record->marketKey,
                ),
            );
        }
    }

    /**
     * Refuse a result that no longer agrees with its own published winning
     * numbers — the same statements DrawSettlementSimulationService makes,
     * kept byte-for-byte in intent so simulation and money settle against
     * exactly the same proof.
     *
     * @throws SettlementSimulationException
     */
    private function assertResultIntegrity(int $drawId, DrawResultData $data): void
    {
        /** @var list<WinningNumber> $rows */
        $rows = WinningNumber::query()
            ->where('draw_id', $drawId)
            ->orderBy('id')
            ->get()
            ->all();

        if ($rows === []) {
            throw SettlementSimulationException::resultTampered(
                $drawId,
                'no published winning number row exists for it, so the published result cannot be '
                .'confirmed against the per market numbers it was published with',
            );
        }

        $tiersSeen = [];

        foreach ($rows as $row) {
            $tier = $row->prize_tier;

            $resultType = is_string($tier) ? MarketResultType::tryFrom($tier) : null;

            if (! $resultType instanceof MarketResultType) {
                throw SettlementSimulationException::resultTampered(
                    $drawId,
                    sprintf(
                        'published winning number %d carries prize tier %s, which is not a known market '
                        .'result type',
                        (int) $row->getKey(),
                        $tier === null ? 'NULL' : $tier,
                    ),
                    ['winning_number_id' => (int) $row->getKey()],
                );
            }

            $tiersSeen[$resultType->value] = true;

            $expected = $data->valueFor($resultType);
            $stored = (string) $row->number;

            // Strict string comparison. '07' and '7' are different published
            // numbers and a numeric comparison would call them equal.
            if ($stored !== $expected) {
                throw SettlementSimulationException::resultTampered(
                    $drawId,
                    sprintf(
                        'published winning number %d for prize tier %s stores %s, but the stored result '
                        .'derives %s for that tier',
                        (int) $row->getKey(),
                        $resultType->value,
                        $stored,
                        $expected,
                    ),
                    [
                        'winning_number_id' => (int) $row->getKey(),
                        'prize_tier' => $resultType->value,
                        'stored_number' => $stored,
                        'derived_number' => $expected,
                    ],
                );
            }
        }

        foreach (MarketResultType::cases() as $resultType) {
            if (! isset($tiersSeen[$resultType->value])) {
                throw SettlementSimulationException::resultTampered(
                    $drawId,
                    sprintf(
                        'no published winning number remains for prize tier %s, so a market of that tier '
                        .'has no official number to be settled against',
                        $resultType->value,
                    ),
                    ['missing_prize_tier' => $resultType->value],
                );
            }
        }
    }

    /**
     * The aggregate figures of the payouts this draw actually produced.
     *
     * @return array{count: int, total: string}
     */
    private function storedPayoutStats(int $drawId): array
    {
        $rows = Payout::query()
            ->where('draw_id', $drawId)
            ->whereNull('deleted_at')
            ->selectRaw('amount')
            ->pluck('amount');

        $total = '0.00';

        foreach ($rows as $amount) {
            $total = bcadd($total, (string) $amount, 2);
        }

        return ['count' => $rows->count(), 'total' => $total];
    }

    /**
     * The bets of a draw, locked for update, in a stable order.
     *
     * Ordered by primary key so concurrent runs take the row locks in the same
     * sequence and cannot deadlock against one another. User and ticket come
     * along for the wallet resolution and payout reference of the money path.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Bet>
     */
    private function lockedBets(int $drawId)
    {
        return Bet::query()
            ->with(['ticket', 'user'])
            ->where('draw_id', $drawId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * The selections of one bet, locked for update, in a stable order.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BetItem>
     */
    private function lockedItems(int $betId)
    {
        return BetItem::query()
            ->where('bet_id', $betId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * The bets of a draw, unlocked, for the read-only replay path.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Bet>
     */
    private function betsFor(int $drawId)
    {
        return Bet::query()
            ->with(['ticket', 'user'])
            ->where('draw_id', $drawId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The selections of a bet, unlocked, for the read-only replay path.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BetItem>
     */
    private function itemsFor(int $betId)
    {
        return BetItem::query()
            ->where('bet_id', $betId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Keep the run to one currency. A draw mixing currencies would produce a
     * meaningless payout total; refused rather than silently summed.
     *
     * @throws SettlementSimulationException
     */
    private function assertSingleCurrency(int $drawId, ?Currency $current, Bet $bet): Currency
    {
        $betCurrency = $bet->currency;

        if (! $betCurrency instanceof Currency) {
            throw SettlementSimulationException::selectionUnreadable(
                (int) $bet->getKey(),
                'the bet has no usable currency',
                ['draw_id' => $drawId],
            );
        }

        if ($current !== null && $current !== $betCurrency) {
            throw SettlementSimulationException::selectionUnreadable(
                (int) $bet->getKey(),
                sprintf(
                    'draw %d mixes currencies (%s and %s); one settlement run reports one total, so '
                    .'this is refused rather than summed',
                    $drawId,
                    $current->value,
                    $betCurrency->value,
                ),
                ['draw_id' => $drawId],
            );
        }

        return $betCurrency;
    }

    /**
     * The deterministic payout reference for one winning bet of a draw.
     * Uniqueness of payouts.reference_number (which also covers soft-deleted
     * rows) makes this the bottom-layer idempotency anchor of the whole run.
     */
    private function payoutReference(int $drawId, int $betId): string
    {
        $prefix = $this->config->get('lottery.payouts.reference_prefix');

        if (! is_string($prefix) || $prefix === '') {
            $prefix = 'PO';
        }

        return sprintf('%s-D%06d-B%08d', $prefix, $drawId, $betId);
    }

    /**
     * The idempotency key for the wallet credit of one winning bet. Retried
     * credits with this key return the original financial transaction via the
     * finance engine's claim table instead of double-crediting.
     */
    private function payoutIdempotencyKey(int $drawId, int $betId): string
    {
        return sprintf('payout-draw-%d-bet-%d', $drawId, $betId);
    }

    /**
     * The configured default betting currency, used only when a draw has no
     * bets to take a currency from.
     */
    private function defaultCurrency(): Currency
    {
        $configured = $this->config->get('lottery.betting.currency');

        if (is_string($configured)) {
            $currency = Currency::tryFrom($configured);

            if ($currency instanceof Currency) {
                return $currency;
            }
        }

        return Currency::THB;
    }

    /**
     * Record the run in audit_logs. A money-moving run is always High risk:
     * every settlement writes wallet balances and balanced ledger postings, so
     * the audit line for it must survive the same scrutiny as a manual payout.
     */
    private function recordAudit(Draw $draw, SettlementResult $settlement): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => RiskLevel::High,
            'auditable_type' => Draw::class,
            'auditable_id' => $draw->getKey(),
            'description' => sprintf(
                'Monetary settlement of draw %s: %d selection(s) evaluated, %d winning, %d payout(s) '
                .'created totalling %s %s across %d bet(s).',
                (string) $draw->draw_number,
                $settlement->selectionsEvaluated,
                $settlement->winningSelections,
                $settlement->payoutsCreated,
                $settlement->totalPayoutAmount,
                $settlement->currency,
                $settlement->betsUpdated,
            ),
            'old_values' => ['status' => $settlement->stateBefore->toDrawStatus()->value],
            'new_values' => ['status' => $settlement->stateAfter->toDrawStatus()->value],
            'metadata' => [
                'mode' => self::MODE,
                'draw_id' => $settlement->drawId,
                'first_prize' => $settlement->firstPrize,
                'bottom_two' => $settlement->bottomTwo,
                'selections_evaluated' => $settlement->selectionsEvaluated,
                'selections_written' => $settlement->selectionsWritten,
                'bets_updated' => $settlement->betsUpdated,
                'winning_selections' => $settlement->winningSelections,
                'total_stake' => $settlement->totalStake,
                'total_prize' => $settlement->totalPrize,
                'payouts_created' => $settlement->payoutsCreated,
                'total_payout_amount' => $settlement->totalPayoutAmount,
                'currency' => $settlement->currency,
                'status_counts' => $settlement->statusCounts(),
                'monetary' => true,
                'wallet_modified' => $settlement->payoutsCreated > 0,
                'ledger_modified' => $settlement->payoutsCreated > 0,
                'financial_transaction_created' => $settlement->payoutsCreated > 0,
                'payout_created' => $settlement->payoutsCreated > 0,
            ],
        ]);

        $log->save();
    }
}
