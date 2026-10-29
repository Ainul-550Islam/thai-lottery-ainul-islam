<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\Betting\BetCancellationData;
use App\DTOs\Betting\BetCancellationResult;
use App\Enums\AuditAction;
use App\Enums\BetCancellationReason;
use App\Enums\BetStatus;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\RiskLevel;
use App\Enums\TicketStatus;
use App\Enums\WalletType;
use App\Exceptions\BetAlreadySettledException;
use App\Exceptions\BetCancellationException;
use App\Exceptions\BetCancellationWindowExpiredException;
use App\Models\AgentCommission;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\Ticket;
use App\Models\Wallet;
use App\Models\NumberLimit;
use App\Services\Agent\BetCommissionCancellation;
use App\Services\Draw\DrawCancellationService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use App\Services\Risk\BetRiskOwnershipFloor;
use App\Services\Risk\RiskAssessmentService;
use Illuminate\Database\DatabaseManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Player-facing bet cancellation with a full, immediate refund.
 *
 * PLACE IN THE SYSTEM
 * DrawLifecycleService cancels DRAWS (operator flow). The real GLO distinction
 * between "the whole draw is off" and "I bought the wrong number, give me my
 * stake back before the draw" is served by THIS service: one bet, owner
 * initiated, inside a short window, refunded in full right away.
 *
 * THE MONEY LEG REUSES THE SAME PRIMITIVE AS DEPOSITS
 * FinancialTransactionService::execute (through WalletService::credit) is the
 * only audited wallet+ledger movement on this codebase. The refund is therefore
 * one atomic step: FinancialTransaction row, wallet credit and double-entry
 * posting commit together or not at all. The cancellation adds its own single
 * transaction around the marking of the bet, the risk release and that credit,
 * so a refunded-but-still-active bet is unrepresentable.
 *
 * REPLAYS
 * Cancelling an already-cancelled bet returns the recorded outcome with
 * replayed=true and never credits twice, regardless of client_key. The refund
 * itself carries a deterministic idempotency key (bet-refund-<padded id>), so
 * even a crash mid-retry cannot double-credit: the idempotency machinery
 * returns the existing FinancialTransaction.
 *
 * RISK RELEASE
 * A purchased bet reserved capacity on the draw's number limits at bet time;
 * cancelling hands that capacity back through RiskAssessmentService::releaseBet
 * with the same selections (numbers, stakes, multipliers) it reserved, inside
 * the same transaction. Another player can buy the number immediately.
 *
 * TICKET SYNC
 * When every bet on the ticket is cancelled the ticket moves to Cancelled with
 * its cancelled_at stamp; a partially cancelled ticket stays in its current
 * state.
 */
class BetCancellationService
{
    /**
     * Deterministic refund idempotency key. The finance idempotency machinery
     * requires keys of at least 16 characters everywhere, so the bet id is
     * zero-padded: every realistic id yields a key of length 22.
     */
    private const REFUND_KEY_FORMAT = 'bet-refund-%012d';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly WalletService $wallets,
        private readonly RiskAssessmentService $risk,
        private readonly BetPurchaseOwnership $ownership,
        private readonly BetPurchaseReceipt $receipts,
        private readonly BetAccountingProof $proof,
        private readonly BetRiskOwnershipFloor $floor,
    ) {
    }

    /**
     * Cancel a bet and refund its stake in full.
     *
     * @throws BetCancellationException
     * @throws BetAlreadySettledException
     * @throws BetCancellationWindowExpiredException
     */
    public function cancel(BetCancellationData $data, ?DrawCancellationService $dispatch = null): BetCancellationResult
    {
        // A draw-dispatch capability is an operator authority, not the player's. When one
        // is presented the player-side switches do not apply: the draw may already be
        // closed, the betting window may have passed and the player refund switch may be
        // off, because the refund is not being made at the player's request — it is being
        // made because the whole draw is being cancelled by staff who hold that duty.
        if ($dispatch === null) {
            $this->assertEnabled();
        }

        $bet = $this->resolveBet($data);
        $currency = $this->resolveCurrency($bet);

        // ---- Replay: already cancelled returns the recorded outcome --------
        if ($bet->status === BetStatus::Cancelled) {
            return $this->replayResult($bet, $currency);
        }

        // ---- Gates ----------------------------------------------------------
        // An operator-initiated cancellation is not a player refund. It carries its own
        // authority and its own reconciliation lane; letting it through here would give
        // an operator-reason call the player's refund path and no second signature.
        if ($data->reason === BetCancellationReason::Operator) {
            throw BetCancellationException::withCode('bet_cancellation_reason_forbidden',
                'Operator cancellations run through the controlled review lane, not the player refund path.');
        }

        $this->assertNotSettled($bet);
        $this->assertCancellableStatus($bet);

        if ($dispatch === null) {
            $this->assertWithinWindow($bet);
            $this->assertDrawAcceptsCancellation($bet);
        }

        /** @var list<BetItem> $items */
        $items = $bet->items()->get()->all();

        $result = null;

        $this->database->transaction(function () use (&$result, $bet, $items, $data, $currency, $dispatch): void {
            // The capability is asserted HERE, inside the transaction, because it is only
            // valid while the draw cancellation that minted it is still 'preparing'. The
            // id it returns is written into the refund, so a later draw-level replay can
            // prove that this exact refund belongs to this exact draw cancellation.
            // ONE STAMP FOR THE WHOLE CANCELLATION.
            //
            // A cancellation writes its instant in four places that are later compared
            // to each other: the bet's terminal stamp, the purchase claim's
            // `cancelled_at`, the reversed commission rows, and the agent compensation
            // rows. If each write called now() separately, the values could differ by a
            // second whenever a boundary falls between them — and the proof that reads
            // them back would refuse a cancellation that was in fact correct, on a
            // schedule nobody controls. The stamp is taken once here and threaded
            // through every write, so the comparison is a check on arithmetic rather
            // than on the clock.
            $stamp = now();

            $drawCancellationId = $dispatch?->assertBetPermit($bet, $data);

            // The bet was read before taking its original wallet lock. Another
            // cancellation can commit while this caller waits, so inspect the
            // current bet under the same wallet-first ordering before deciding
            // whether to refund or to prove the already-recorded refund.
            $route = DB::table('bet_purchase_ownerships')->where('bet_id', (int) $bet->getKey())->first();
            if (! $route) {
                $this->proof->refuse();
            }
            $wallet = app(WalletLockService::class)->lockForCredit((int) $route->wallet_id);
            // Draw coordinators already hold this lock; player cancellation
            // takes it before the bet so closing/cancelling the draw cannot
            // race a stale pre-wallet sales-window decision.
            if ($dispatch === null) {
                Draw::query()->whereKey((int) $bet->draw_id)->lockForUpdate()->firstOrFail();
            }
            $current = Bet::withTrashed()->whereKey((int) $bet->getKey())->lockForUpdate()->first();
            if (! $current instanceof Bet || $current->trashed() || (int) $current->user_id !== $data->userId
                || $current->currency !== $currency) {
                $this->proof->refuse();
            }
            $bet = $current;
            if ($bet->status === BetStatus::Cancelled) {
                $result = $this->proveReplay($bet, $currency, $wallet);

                return;
            }
            $this->assertNotSettled($bet);
            $this->assertCancellableStatus($bet);
            if ($dispatch === null) {
                $this->assertWithinWindow($bet);
                $this->assertDrawAcceptsCancellation($bet);
            }
            $items = $bet->items()->lockForUpdate()->get()->all();

            // 0. Prove that this bet really was bought, paid for and still owns the
            //    capacity being released. Everything below moves money, so the proof
            //    comes first and any inconsistency aborts before a single row changes:
            //    the ownership claim and its terms, the original debit with its two
            //    ledger legs and live accounts, the sealed purchase receipt, the
            //    capacity floor that must still cover the live claims, and the refund
            //    key that must not already belong to somebody else.
            [$claim, $wallet] = $this->proveRefundablePurchase($bet, $currency, $data->reason, $stamp);

            // 1. Mark the bet cancelled. Status columns are not fillable by
            //    design, so forceFill is required and intentional here.
            $bet->forceFill([
                'status' => BetStatus::Cancelled,
                'cancelled_at' => $stamp,
                'cancelled_reason' => $data->reason->value,
            ]);
            $bet->save();

            // 3. Refund the stake through the audited wallet+ledger primitive.
            $refund = $this->wallets->credit(
                $wallet,
                Money::of(bcadd((string) $bet->stake_amount, '0', 2), $currency),
                FinancialTransactionType::BetRefund,
                $this->refundKeyFor((int) $bet->getKey()),
                [
                    'description' => sprintf('Refund for cancelled bet %s', (string) $bet->bet_number),
                    // The column is the polymorphic pointer itself, so it carries the
                    // model class — the same value BetPurchaseWalletService writes for the
                    // original debit. Writing the short classification ('bet') here left
                    // the refund unmorphable and unmatched against its own debit.
                    'reference_type' => Bet::class,
                    'reference_id' => (int) $bet->getKey(),
                    // Bind the refund to the exact claim and original debit it reverses.
                    // Later proofs (amendment ownership, reconciliation) locate the refund
                    // through these, so a refund that points nowhere is refused instead of
                    // being adopted. They live under `metadata`: the finance service
                    // persists `options['metadata']` and ignores loose top-level keys.
                    'metadata' => array_filter([
                        'purchase_ownership_id' => (int) $claim->id,
                        'original_transaction_id' => (int) $claim->original_transaction_id,
                        'draw_cancellation_id' => $drawCancellationId,
                    ], static fn ($value): bool => $value !== null),
                ],
            );

            // 3b. The claim is now revoked AND backed by this exact refund, so the
            //     capacity can go back to the pool. Release happens after the claim
            //     update on purpose: the ownership floor refuses to free capacity a
            //     live claim still covers, so releasing first would either fail or
            //     (worse) succeed while the claim still said the bet was owned.
            $this->markClaimRefunded($claim, $bet, $refund, $data->reason);

            $this->risk->releaseBet(
                (int) $bet->draw_id,
                $bet->type,
                array_map(
                    static fn (BetItem $item): array => [
                        'number' => (string) $item->number,
                        'stake' => bcadd((string) $item->amount, '0', 2),
                        'multiplier' => (string) $item->payout_multiplier,
                    ],
                    $items,
                ),
            );

            // 4. Keep the ticket coherent: cancelled only when nothing on it
            //    remains standing.
            $ticket = $this->syncTicket($bet);

            // 5. Audit trail, same shape as the other finance-facing flows.
            $this->recordAudit($bet, $data->reason, $refund, $drawCancellationId === null ? null : (int) DB::table('draw_cancellation_ownerships')->where('id', $drawCancellationId)->value('actor_user_id'));

            $result = new BetCancellationResult(
                bet: $bet->refresh(),
                ticket: $ticket,
                refund: $refund,
                reason: $data->reason,
                refundAmount: bcadd((string) $refund->amount, '0', 2),
                currency: $currency->value,
                replayed: false,
            );
        }, 3);

        /** @var BetCancellationResult $result */
        return $result;
    }

    // ----------------------------------------------------------------------
    // Gates
    // ----------------------------------------------------------------------

    private function resolveBet(BetCancellationData $data): Bet
    {
        $query = Bet::query()->where('user_id', $data->userId);

        $identifier = $data->betIdentifier;
        $query->where(static function ($q) use ($identifier): void {
            if (ctype_digit($identifier)) {
                $q->where('id', (int) $identifier);
            } else {
                $q->where('bet_number', $identifier)->orWhere('uuid', $identifier);
            }
        });

        /** @var Bet|null $bet */
        $bet = $query->first();

        // Enumeration-safe: wrong owner, unknown number and unknown id are the
        // same refusal, so the endpoint never confirms a bet exists.
        if ($bet === null) {
            throw BetCancellationException::betUnavailable($identifier);
        }

        return $bet;
    }

    private function assertEnabled(): void
    {
        if (! (bool) config('lottery.cancellation.enabled', true)) {
            throw BetCancellationException::disabled();
        }
    }

    private function assertNotSettled(Bet $bet): void
    {
        if (in_array($bet->status, [BetStatus::Won, BetStatus::Lost, BetStatus::Refunded], true)) {
            throw BetAlreadySettledException::forBet((int) $bet->getKey(), $bet->status->value);
        }
    }

    private function assertCancellableStatus(Bet $bet): void
    {
        if (! $bet->status->canCancel()) {
            throw BetCancellationException::notCancellable($bet->status->value, ['bet_id' => $bet->getKey()]);
        }
    }

    private function assertWithinWindow(Bet $bet): void
    {
        $window = (int) config('lottery.cancellation.window_minutes', 15);
        if ($window <= 0) {
            return; // Window disabled by configuration.
        }

        $placedAt = $bet->placed_at ?? $bet->created_at;
        if ($placedAt === null || ! $placedAt->copy()->addMinutes($window)->isFuture()) {
            throw BetCancellationWindowExpiredException::forBet(
                (int) $bet->getKey(),
                $placedAt?->toDateTimeString() ?? 'unknown',
                $window,
            );
        }
    }

    private function assertDrawAcceptsCancellation(Bet $bet): void
    {
        /** @var Draw $draw */
        $query = $bet->draw();
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $draw = $query->firstOrFail();

        if (! $draw->canAcceptBets()) {
            throw BetCancellationException::drawUnavailable((int) $draw->getKey(), 'the draw no longer accepts changes');
        }

        // A status of Open is not enough: the sales window is what makes the bet
        // reversible. Once betting has closed the numbers are already being drawn, so a
        // refund would release capacity nobody can use and pay out a bet that may still
        // win. The cut-off is therefore read from the clock, not inferred from status.
        if ($draw->betting_close_at !== null && ! $draw->betting_close_at->isFuture()) {
            throw BetCancellationException::drawUnavailable((int) $draw->getKey(), 'the betting window for this draw has closed');
        }
    }

    // ----------------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------------

    /**
     * Prove that the bet being cancelled is a paid, owned purchase, and return its
     * still-active ownership claim.
     *
     * Each step answers a different question, and all of them must be answered before
     * money moves:
     *   - the claim exists, belongs to this bet and wallet, and its terms hash still
     *     matches the bet as stored (stake, payout, number, ticket, idempotency key);
     *   - the original debit is completed, unmoved and carries exactly the two ledger
     *     legs it should, against live accounts;
     *   - the sealed receipt still binds that debit to this ticket;
     *   - the risk floor still covers every live claim on the number;
     *   - the refund key is not already in use by another movement.
     */
    private function proveRefundablePurchase(Bet $bet, Currency $currency, BetCancellationReason $reason, CarbonInterface $stamp): array
    {
        // The refund must land in the wallet that actually paid for the bet — a bonus
        // or non-primary wallet, not whichever wallet is the player's default. The
        // claim records that wallet, so it is read first, locked, and only then proved.
        $route = DB::table('bet_purchase_ownerships')->where('bet_id', (int) $bet->getKey())->first();

        if (! $route) {
            $this->proof->refuse();
        }

        $wallet = app(WalletLockService::class)->lockForCredit((int) $route->wallet_id);

        if ($wallet->currency !== $currency) {
            $this->proof->refuse();
        }

        $claim = $this->ownership->locked($bet, $wallet);

        if ($claim->state !== 'active' || $claim->refund_transaction_id !== null
            || $claim->cancelled_at !== null || $claim->cancelled_reason !== null) {
            $this->proof->refuse();
        }

        /** @var Ticket|null $ticket */
        $ticket = Ticket::withTrashed()->whereKey($bet->ticket_id)->first();

        if (! $ticket instanceof Ticket) {
            $this->proof->refuse();
        }

        $original = FinancialTransaction::withTrashed()->whereKey((int) $claim->original_transaction_id)->first();

        if (! $original instanceof FinancialTransaction) {
            $this->proof->refuse();
        }

        // Re-proves the original debit (amounts, both ledger legs, live accounts) and
        // the receipt hash over it, ticket included.
        $this->receipts->verify($bet, $claim, $ticket, $original);

        $limit = NumberLimit::query()->whereKey((int) $claim->number_limit_id)->first();

        if (! $limit instanceof NumberLimit) {
            $this->proof->refuse();
        }

        $this->floor->assertCovered($limit);

        // An attributed sale is not the player's alone to reverse: paying the stake back
        // while the agent's commission stands would leave money paid against a bet that
        // no longer exists. The commission is therefore cancelled here, in this same
        // transaction, before a single cent is refunded.
        $this->cancelAttributedCommissions($bet, $claim, $wallet, $reason, $stamp);

        if (FinancialTransaction::withTrashed()->where('idempotency_key', $this->refundKeyFor((int) $bet->getKey()))->exists()) {
            $this->proof->refuse();
        }

        return [$claim, $wallet];
    }

    /**
     * Cancel the agent commission an attributed sale earned, inside this transaction.
     *
     * A sale that earned commission is not the player's alone to reverse: refunding the
     * stake while the commission stands would leave money paid against a bet that no
     * longer exists. Only a sale whose commission is fully owned by this exact purchase
     * may be coordinated here — the commission claims, their amounts and the agent book
     * are proved against the purchase snapshot by the commission coordinator itself.
     *
     * Anything else refuses: an unowned commission row, a soft-deleted hidden one, or a
     * snapshot that does not account for every live row is history this lane has no
     * proof to cancel, and guessing would move an agent's money on a guess.
     */
    private function cancelAttributedCommissions(Bet $bet, object $claim, Wallet $wallet, BetCancellationReason $reason, CarbonInterface $stamp): void
    {
        $live = AgentCommission::withTrashed()
            ->where('bet_id', (int) $bet->getKey())
            ->whereNotIn('status', [CommissionStatus::Cancelled, CommissionStatus::Reversed])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // "No live commission row" is only a clean sale when the purchase never earned
        // commission. A purchase whose own snapshot claims commission, but whose rows
        // are missing, detached or hidden, is history this lane cannot cancel - it must
        // not slip through as an ordinary refund.
        $snapshotCount = $claim->commission_snapshot_count === null ? 0 : (int) $claim->commission_snapshot_count;

        if ($live->isEmpty()) {
            // A purchase snapshot that counts zero commissions is an honest unreferred
            // sale: there is nothing to cancel and the member's refund is whole on its
            // own. Anything else is history this lane cannot account for.
            if ($snapshotCount === 0) {
                return;
            }

            throw BetCancellationException::withCode('bet_cancellation_commission_workflow_required',
                'This bet carries an attributed commission; use the coordinated cancellation workflow.');
        }

        if ($claim->commission_snapshot_hash === null || $snapshotCount !== $live->count()) {
            throw BetCancellationException::withCode('bet_cancellation_commission_workflow_required',
                'This bet carries an attributed commission; use the coordinated cancellation workflow.');
        }

        app(BetCommissionCancellation::class)->cancelUnpaid(
            $bet,
            $claim,
            $reason->value,
            $stamp->toDateTimeString(),
            $this->commissionPayoutWallets($bet),
            $wallet,
        );
    }

    /**
     * Agent wallets named by this bet's commission payout receipts, locked in id order.
     *
     * A paid commission is compensated out of the wallet it was actually paid into, and
     * only its receipt says which wallet that was. Nothing is inferred from the agent's
     * current defaults, and the ids are locked in a stable order so two cancellations
     * cannot deadlock each other.
     *
     * @return array<int, Wallet>
     */
    private function commissionPayoutWallets(Bet $bet): array
    {
        $receipts = DB::table('agent_commission_payouts')
            ->whereIn('commission_id', DB::table('agent_commissions')->where('bet_id', (int) $bet->getKey())->select('id'))
            ->orderBy('wallet_id')
            ->get();

        $wallets = [];

        foreach ($receipts->pluck('wallet_id')->map(static fn ($id): int => (int) $id)->unique()->sort()->values() as $walletId) {
            $wallets[$walletId] = app(WalletLockService::class)->lock($walletId);
        }

        return $wallets;
    }

    /**
     * Revoke the claim and bind it to the refund that paid the player back.
     *
     * The refund hash is the claim's own proof for every later read: a replay, a
     * reconciliation report or an operator investigating the bet can recompute it from
     * the claim, the bet and the refund row, so a refund that was edited, moved to
     * another wallet or deleted after the fact no longer matches.
     */
    private function markClaimRefunded(object $claim, Bet $bet, FinancialTransaction $refund, BetCancellationReason $reason): void
    {
        $stamp = now();

        DB::table('bet_purchase_ownerships')->where('id', (int) $claim->id)->update([
            'state' => 'cancelled',
            'refund_transaction_id' => (int) $refund->getKey(),
            'refund_hash' => $this->refundHash($claim, $bet, $refund),
            'cancelled_at' => (string) $bet->cancelled_at?->toDateTimeString(),
            'cancelled_reason' => $reason->value,
            'updated_at' => $stamp,
        ]);
    }

    /**
     * The claim's refund proof is the accounting hash of the refund movement itself.
     *
     * Using the shared proof (rather than a private hash) means the same function that
     * validated the original debit validates the refund: two posting legs, the right
     * accounts, the right wallet, exactly the stake. Any later edit to the refund row,
     * its ledger entries or its accounts changes the hash and the claim stops proving.
     */
    private function refundHash(object $claim, Bet $bet, FinancialTransaction $refund): string
    {
        $wallet = Wallet::query()->whereKey((int) $claim->wallet_id)->firstOrFail();

        [, $hash] = $this->proof->transaction((int) $refund->getKey(), $bet, $wallet, true);

        return $hash;
    }

    /**
     * The recorded outcome of an earlier cancellation, re-proven end to end.
     *
     * A replay is a statement about money that already moved, so it is not enough to
     * find a refund row that mentions this bet. The claim must say cancelled, the
     * refund must still be completed, in the right wallet, for exactly the stake, with
     * its two ledger legs intact, the hash must still match, and the terminal stamps on
     * the bet must be the ones the claim recorded.
     */
    private function replayResult(Bet $bet, Currency $currency): BetCancellationResult
    {
        // Every proof below takes row locks, and a lock outside a transaction protects
        // nothing. The replay therefore reads inside its own short transaction: it
        // writes no rows, but the rows it reads must not move while it proves them.
        return $this->database->transaction(fn (): BetCancellationResult => $this->proveReplay($bet, $currency), 3);
    }

    /**
     * Prove an already-cancelled bet from its own ownership row, for a caller that is
     * already holding the locks.
     *
     * This is the read-only half of a cancellation, exposed for coordinators that cancel
     * many bets inside one transaction (a draw cancellation, for instance): they must be
     * able to prove every member's refund against the rows, without a second, parallel
     * implementation of the same proof. It never writes and it never acquires a wallet
     * lock the caller has not already taken — a coordinator that locked its wallets in
     * numeric order must not have a new one appear underneath the draw row.
     */
    public function proveOwnedCancellation(int $betId, array $lockedWallets = []): BetCancellationResult
    {
        if ($this->database->transactionLevel() < 1) {
            throw BetCancellationException::withCode('bet_cancellation_transaction_required',
                'A cancellation proof reads under row locks and must run inside a transaction.');
        }

        $bet = Bet::withTrashed()->whereKey($betId)->lockForUpdate()->first();

        if (! $bet instanceof Bet || $bet->trashed()) {
            $this->proof->refuse();
        }

        $currency = $this->resolveCurrency($bet);
        $route = DB::table('bet_purchase_ownerships')->where('bet_id', $betId)->lockForUpdate()->first();

        if (! $route) {
            $this->proof->refuse();
        }

        $walletId = (int) $route->wallet_id;
        $wallet = $lockedWallets[$walletId] ?? null;

        if ($lockedWallets !== [] && ! $wallet instanceof Wallet) {
            // The caller holds a set of wallets; this member's is not in it, so the set it
            // proved its own membership against is stale. Acquiring it now would break the
            // lock order the coordinator established.
            $this->proof->refuse();
        }

        $result = $this->proveReplay($bet, $currency, $wallet instanceof Wallet ? $wallet : null);

        // proveReplay() also closes the agent book, so there is no second, parallel
        // commission proof here.
        return $result;
    }

    private function proveReplay(Bet $bet, Currency $currency, ?Wallet $lockedWallet = null): BetCancellationResult
    {
        $route = DB::table('bet_purchase_ownerships')->where('bet_id', (int) $bet->getKey())->first();

        if (! $route) {
            $this->proof->refuse();
        }

        $wallet = $lockedWallet instanceof Wallet
            ? $lockedWallet
            : app(WalletLockService::class)->lockForCredit((int) $route->wallet_id);
        $current = Bet::withTrashed()->whereKey((int) $bet->getKey())->lockForUpdate()->first();
        if (! $current instanceof Bet || $current->trashed() || $current->status !== BetStatus::Cancelled
            || (int) $current->user_id !== (int) $bet->user_id || $current->currency !== $currency) {
            $this->proof->refuse();
        }
        $bet = $current;
        $claim = $this->ownership->locked($bet, $wallet);

        if ($claim->state !== 'cancelled' || $claim->refund_transaction_id === null || ! $claim->refund_hash
            || $claim->cancelled_at === null || $claim->cancelled_reason === null
            || $claim->cancelled_reason !== (string) $bet->cancelled_reason
            || $claim->cancelled_at !== $bet->cancelled_at?->toDateTimeString()) {
            $this->proof->refuse();
        }

        /** @var Ticket|null $ticket */
        $ticket = Ticket::withTrashed()->whereKey($bet->ticket_id)->first();

        if (! $ticket instanceof Ticket) {
            $this->proof->refuse();
        }

        // The refund may have committed while this transaction was waiting
        // after establishing an older snapshot. Prove current transaction rows.
        $original = FinancialTransaction::withTrashed()->whereKey((int) $claim->original_transaction_id)->lockForUpdate()->first();
        $refund = FinancialTransaction::withTrashed()->whereKey((int) $claim->refund_transaction_id)->lockForUpdate()->first();

        if (! $original instanceof FinancialTransaction || ! $refund instanceof FinancialTransaction) {
            $this->proof->refuse();
        }

        $this->receipts->verify($bet, $claim, $ticket, $original);

        if ($refund->idempotency_key !== $this->refundKeyFor((int) $bet->getKey())) {
            $this->proof->refuse();
        }

        // Re-proves the refund itself (completed, unreversed, this wallet, this bet, and
        // exactly the two posting legs the reversal of the purchase implies) and checks
        // the claim still carries that exact proof.
        [, $hash] = $this->proof->transaction((int) $refund->getKey(), $bet, $wallet, true);

        if (! hash_equals((string) $claim->refund_hash, $hash)) {
            $this->proof->refuse();
        }

        // The refund is only half of a coordinated cancellation. When the sale earned
        // commission, recovering that commission is the other half, and a replay that
        // proved the player's money but not the agent's would report a closed
        // cancellation over an open - or a corrupt - agent book. This runs on the
        // replay path too, not only for a coordinator: an ordinary repeated cancel()
        // must prove the same closure as the draw-level coordinator.
        if ($route->commission_snapshot_hash !== null) {
            app(BetCommissionCancellation::class)->assertRecoveryClosed($bet, $route);
        }

        return new BetCancellationResult(
            bet: $bet,
            ticket: $this->syncTicketView($bet),
            refund: $refund,
            reason: BetCancellationReason::tryFrom((string) $bet->cancelled_reason) ?? BetCancellationReason::PlayerRequest,
            refundAmount: bcadd((string) $refund->amount, '0', 2),
            currency: $currency->value,
            replayed: true,
        );
    }

    private function syncTicketView(Bet $bet): ?Ticket
    {
        /** @var Ticket|null $ticket */
        $ticket = Ticket::withTrashed()->whereKey($bet->ticket_id)->first();

        return $ticket;
    }

    /**
     * Cancel the owning ticket only when every bet on it is now cancelled.
     * Partial tickets stay in their current status.
     */
    private function syncTicket(Bet $bet): ?Ticket
    {
        /** @var Ticket|null $ticket */
        $ticket = $bet->ticket()->first();
        if ($ticket === null || $ticket->status === TicketStatus::Cancelled) {
            return $ticket;
        }

        $hasStandingBets = $ticket->bets()
            ->where('status', '!=', BetStatus::Cancelled->value)
            ->exists();

        if (! $hasStandingBets) {
            $ticket->forceFill([
                'status' => TicketStatus::Cancelled,
                'cancelled_at' => now(),
            ]);
            $ticket->save();
        }

        return $ticket;
    }

    private function walletFor(int $userId, Currency $currency): Wallet
    {
        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()
            ->where('user_id', $userId)
            ->where('type', WalletType::Primary->value)
            ->where('currency', $currency->value)
            ->first();

        if ($wallet === null) {
            throw BetCancellationException::betUnavailable((string) $userId, ['anomaly' => 'primary_wallet_missing']);
        }

        return $wallet;
    }

    private function resolveCurrency(Bet $bet): Currency
    {
        return $bet->currency instanceof Currency
            ? $bet->currency
            : (Currency::tryFrom((string) $bet->currency) ?? Currency::THB);
    }

    /**
     * Record the cancellation.
     *
     * `$actorUserId` is set when somebody other than the player caused the refund — an
     * operator cancelling the whole draw. Attributing those refunds to the player would
     * say the player asked for a cancellation they never made, and would leave the audit
     * trail unable to answer who actually moved the money.
     */
    private function recordAudit(Bet $bet, BetCancellationReason $reason, FinancialTransaction $refund, ?int $actorUserId = null): void
    {
        $log = new AuditLog();
        $log->fill([
            'user_id' => $actorUserId ?? (int) $bet->user_id,
            'action' => AuditAction::CancelBet,
            'risk_level' => RiskLevel::Low,
            'auditable_type' => Bet::class,
            'auditable_id' => (int) $bet->getKey(),
            'description' => sprintf(
                'Cancelled bet %s (%s); refunded %s %s via %s.',
                (string) $bet->bet_number,
                $reason->value,
                bcadd((string) $bet->stake_amount, '0', 2),
                (string) ($bet->currency instanceof Currency ? $bet->currency->value : $bet->currency),
                (string) $refund->reference_number,
            ),
            'metadata' => array_filter([
                'bet_id' => (int) $bet->getKey(),
                'bet_number' => (string) $bet->bet_number,
                'draw_id' => (int) $bet->draw_id,
                'reason' => $reason->value,
                'refund_transaction_id' => (int) $refund->getKey(),
                'on_behalf_of_user_id' => $actorUserId !== null ? (int) $bet->user_id : null,
            ], static fn ($value): bool => $value !== null),
        ]);
        $log->save();
    }

    public function refundKeyFor(int $betId): string
    {
        return sprintf(self::REFUND_KEY_FORMAT, $betId);
    }
}
