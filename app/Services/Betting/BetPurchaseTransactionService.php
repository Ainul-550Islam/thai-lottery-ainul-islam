<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetPurchaseContext;
use App\DTOs\BetPurchaseResult;
use App\Exceptions\BetPurchaseConcurrencyException;
use App\Exceptions\BetPurchaseException;
use App\Models\Bet;
use App\Models\User;
use App\Services\Agent\AgentCommissionAccrualService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Owns the single database transaction in which a bet purchase becomes real.
 *
 * WHY ONE TRANSACTION, OWNED IN ONE PLACE
 * Atomicity is not something each collaborator can contribute a share of. Either every
 * mutation of a purchase is inside one transaction or the guarantee does not exist. So
 * exactly one class opens a transaction for a purchase, and it is this one. The wallet,
 * risk, bet, item, ticket and ledger services all ASSERT they are inside a transaction
 * and refuse to run otherwise, which means no collaborator can be misused into mutating
 * anything outside this boundary.
 *
 * THE ORDER, AND WHY EACH STEP IS WHERE IT IS
 *   1. LOCK THE WALLET. First, always, on every path. Fixing the lock order at the
 *      wallet is what makes a lock-order deadlock between two purchases impossible: two
 *      purchases can never hold each other's next lock, because both want the wallet
 *      before they want a number limit.
 *   2. RE-CHECK IDEMPOTENCY UNDER THE LOCK. The pre-transaction check happened without
 *      a lock, so a concurrent identical request may have committed since. Re-reading
 *      here, holding the wallet lock, closes that window for two requests from the same
 *      player. The insert's UNIQUE constraint closes it for every other case.
 *   3. RE-CHECK RESPONSIBLE-GAMING LIMITS under the wallet lock. Parallel bets and
 *      deposits for this player cannot both spend the same rolling-limit headroom.
 *   4. CHECK THE BALANCE AGAINST THE LOCKED ROW. Before any row is created and before
 *      any capacity is reserved, so an unaffordable bet consumes nothing at all.
 *   5. RESERVE RISK CAPACITY. Under SELECT ... FOR UPDATE on the number_limits row,
 *      decided by Phase 3.1 from counters it re-read under that lock.
 *   6. CREATE THE BET. Needs to exist before the debit, because the debit's polymorphic
 *      reference points at it.
 *   7. CREATE THE ITEM. One line, one charge.
 *   8. CREATE THE TICKET AND LINK IT. The Phase 1 foreign key is bets.ticket_id, so the
 *      ticket must exist before the bet can point at it.
 *   9. DEBIT THE WALLET. Phase 2.1 performs the debit, the double-entry posting and its
 *      own balance assertion as one unit, in a nested transaction - a SAVEPOINT inside
 *      this one - so an outer rollback still discards all of it.
 *  10. VERIFY THE LEDGER. Proven present, linked to this bet and balanced, before the
 *      commit is allowed. A purchase may not commit money that the books do not record.
 *  11. PROMOTE THE BET AND THE TICKET. Only paid-for, balanced purchases become Active
 *      and Confirmed.
 *
 * WHY THERE IS NO COMPENSATION OR CLEANUP CODE
 * Rollback is the database's job and it does it correctly. Any failure at any step
 * aborts the closure, Laravel rolls the transaction back, and every mutation above -
 * the counter increment, the bet, the item, the ticket, the wallet balance, the
 * financial transaction, the ledger entries - is discarded together. Hand-written
 * compensation (release the reservation, credit the wallet back, delete the ticket) is
 * how partial states are CREATED: it runs after the rollback has already undone the
 * thing it is compensating for, and so double-undoes it. There is deliberately none.
 *
 * WHY QueryException IS CLASSIFIED RATHER THAN SWALLOWED
 * A duplicate key on bets.idempotency_key means a concurrent identical request won the
 * race, which is a REPLAY and not an error the caller should see. A deadlock or lock
 * timeout is a transient contention failure worth retrying. Every other database error
 * is a real failure and is re-thrown. Nothing is retried blindly.
 */
final class BetPurchaseTransactionService
{
    /**
     * Attempts for the whole purchase closure when it fails on lock contention.
     */
    public const DEFAULT_ATTEMPTS = 3;

    public function __construct(
        private readonly BetPurchaseWalletService $wallets,
        private readonly BetPurchaseRiskService $risk,
        private readonly BetPurchaseBetService $bets,
        private readonly BetPurchaseItemService $items,
        private readonly BetPurchaseTicketService $tickets,
        private readonly BetPurchaseLedgerService $ledger,
        private readonly BetPurchaseIdempotencyService $idempotency,
        private readonly AgentCommissionAccrualService $commissionAccrual,
        private readonly BetPurchaseOwnership $ownership,
        private readonly BetPurchaseReceipt $receipts,
        private readonly BetAmendmentOwnership $amendments,
        private readonly BetPurchaseAdmission $admission,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Execute the whole purchase atomically.
     *
     * @throws BetPurchaseConcurrencyException when a concurrent identical request won,
     *                                         or when contention could not be resolved
     * @throws BetPurchaseException on any refusal; nothing is persisted
     */
    public function execute(BetPurchaseContext $context, ?\App\Services\Betting\BetAmendmentPermit $permit = null): BetPurchaseResult
    {
        $this->assertNotAlreadyInTransaction();

        $attempts = $this->attempts();
        $delay = max(0, (int) config('finance.locking.retry_delay_milliseconds', 150));

        // The retry lives here, not inside DB::transaction(), because Laravel only
        // recognises its own list of concurrency messages. A busy SQLite database
        // (codes 5/6/517) is lock contention all the same, and only this layer knows
        // the purchase's retry budget: the driver's verdict is read from the error code
        // and the whole closure is replayed from scratch, so a rolled-back attempt can
        // never leak a partially written bet.
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(
                    fn (): BetPurchaseResult => $this->purchase($context, $permit),
                    1,
                );
            } catch (QueryException $exception) {
                if (! BetPurchaseConcurrencyException::isLockFailure($exception) || $attempt >= $attempts) {
                    // Exhausted, or a failure that is a fact rather than an attempt.
                    // Nothing is persisted either way: the transaction is closed.
                    throw $this->classify($exception, $context);
                }

                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }
        }
    }

    /**
     * The purchase body. Runs entirely inside the transaction.
     *
     * @throws BetPurchaseConcurrencyException
     * @throws BetPurchaseException
     */
    private function purchase(BetPurchaseContext $context, ?\App\Services\Betting\BetAmendmentPermit $permit = null): BetPurchaseResult
    {
        // 1. Wallet row lock. First lock on every path.
        $wallet = $this->wallets->lockForDebit($context->walletId(), $context->currency());

        // 2. Idempotency, re-checked while holding the lock.
        $existing = $this->bets->lockByIdempotencyKey($context->betIdempotencyKey);

        if ($existing instanceof Bet) {
            // Abort rather than continue. Throwing unwinds cleanly and the caller
            // replays the committed bet, so the retry never becomes a second purchase.
            throw BetPurchaseConcurrencyException::idempotentRaceLost(
                $context->betIdempotencyKey,
                $context->userId(),
                (int) $existing->getKey(),
            );
        }

        // 2b. Refund exposure, the cheap half: is there a proved-but-unrecovered deposit
        // refund for this player in this currency? Read before the risk locks so a player
        // who is fenced off cannot consume capacity, and read under the player lock so a
        // concurrent recovery cannot slip between the read and the reservation.
        $this->assertNoRefundExposure($context, false);

        // 3. Admission, re-decided against CURRENT rows while the wallet is locked.
        // A context validated a moment ago is not permission: the account may have been
        // suspended, self-excluded or pushed over a limit since. Admission takes the
        // player mutex, re-reads the account and the wallet's owner/currency, refuses
        // any pending refund exposure, and re-evaluates the responsible-gaming totals
        // (mutable financial projections) under that same lock, so two simultaneous
        // bets cannot both pass the same daily/weekly/monthly ceiling using one stale
        // snapshot. The early checks in BetPurchaseService stay as a fast refusal; this
        // call is the authoritative, concurrency-safe decision.
        $this->admission->assertAllowed($context, $wallet);

        // 4. Affordability, against the locked row.
        $this->wallets->assertSufficientBalance($wallet, $context->stakeMoney());

        // 5. Risk capacity, under the number_limits row lock. No bypass exists.
        $reservation = $this->risk->reserve($context);

        // 5b. Refund exposure, the authoritative half: with the risk locks held and before
        // any bet, ticket or debit row exists, every pending custody row is re-proved from
        // its own recovery history. A caller that hand-built a context and called this
        // service directly never passed a preflight, and a preview switch is a performance
        // flag rather than an authority, so this decision is taken here every time.
        $this->assertNoRefundExposure($context, true);

        // 6. The bet.
        $bet = $this->bets->create($context);

        // 7. The one item line.
        $item = $this->items->create($bet, $context);

        // 8. The ticket, then the link.
        $ticket = $this->tickets->create($context);
        $bet = $this->bets->attachTicket($bet, $ticket);

        // 9. The money. Phase 2.1 debits, posts the ledger and asserts its own balance.
        $transaction = $this->wallets->debitForBet(
            lockedWallet: $wallet,
            stake: $context->stakeMoney(),
            bet: $bet,
            idempotencyKey: $context->financialIdempotencyKey,
            metadata: [
                'bet_id' => (int) $bet->getKey(),
                'bet_number' => (string) $bet->bet_number,
                'ticket_id' => (int) $ticket->getKey(),
                'ticket_number' => (string) $ticket->ticket_number,
                'draw_id' => $context->drawId(),
                'market' => $context->marketKey,
                'number' => $context->canonicalNumber(),
                'bet_idempotency_key' => $context->betIdempotencyKey,
            ],
        );

        // 10. The books, proven before the commit is allowed.
        $ledgerEntryIds = $this->ledger->assertPostedForBet($transaction, $bet);

        // 11. Promotion. Only now is the purchase paid for and recorded.
        $bet = $this->bets->markPlaced($bet);
        $ticket = $this->tickets->confirm($ticket);

        $this->assertExactlyOneItem($bet, $context);

        // 12. Agent commissions accrue on real, paid turnover — never on an
        // unpaid, unposted or half-promoted purchase. Accruing here, inside the
        // same atomic unit, means a purchase that later cannot commit takes its
        // commission rows down with it, and a committed purchase can never
        // have existed without its commissions being written. The accrual
        // service itself resolves the referral chain (an unattributed player
        // yields no rows) and skips ineligible agents.
        $this->commissionAccrual->accrueForBet($bet);

        // 13. The durable ownership claim. A paid, promoted purchase owns exactly the
        // capacity it reserved, and every downstream money path (refund, cancellation,
        // amendment, replay proof, exposure floor) reads that claim rather than the
        // aggregate counter. Recording it here — last, inside the same transaction, and
        // only after the debit has been proven posted — means an ownership row can never
        // exist without the money that funded it, and a purchase that cannot record its
        // claim rolls back whole instead of leaving capacity nobody owns.
        $this->ownership->record($bet, $wallet, $transaction, $reservation);

        // 14. The sealed original receipt. It binds this one bet, its ticket, its
        // ownership claim and its original debit into a single hash, so a later replay
        // can prove the purchase was complete rather than infer it from whichever rows
        // happen to still exist. Written last for the same reason as the claim: it can
        // never exist for a purchase whose money did not post.
        $this->receipts->record($bet, $wallet, $transaction, $ticket);

        // 15. If this purchase is the replacement leg of an amendment, the amendment
        // ownership row is flipped to applied here — with the replacement's own funded
        // debit and ownership claim as the proof. An ordinary purchase carries no permit
        // and finds no row, so it acquires nothing; a purchase that presents a permit it
        // cannot satisfy aborts the whole transaction.
        $this->amendments->captureReplacement($bet, $wallet, $transaction, $permit);

        return BetPurchaseResult::purchased(
            bet: $bet,
            item: $item,
            ticket: $ticket,
            transaction: $transaction,
            stake: $context->stake,
            potentialPayout: $context->potentialPayout,
            idempotencyKey: $context->betIdempotencyKey,
            ledgerEntryIds: $ledgerEntryIds,
            riskReservation: $reservation,
            context: [
                'market' => $context->marketKey,
                'number' => $context->canonicalNumber(),
                'covered_number_count' => $context->permutationCount(),
                'ledger_totals' => $this->ledger->totalsFor($transaction),
                'wallet_available_after' => $this->wallets
                    ->availableBalance($wallet->refresh())
                    ->toString(),
            ],
        );
    }

    /**
     * One purchase creates one charge. Asserted from the database, inside the
     * transaction, so a purchase that somehow produced two lines rolls back instead of
     * committing a double charge.
     *
     * @throws BetPurchaseException
     */
    private function assertExactlyOneItem(Bet $bet, BetPurchaseContext $context): void
    {
        $count = $this->items->itemsFor($bet)->count();

        if ($count !== 1) {
            throw BetPurchaseException::invariantViolated(
                sprintf(
                    'the purchase created %d bet item lines for one selection on market %s, and one selection is one charge',
                    $count,
                    $context->marketKey,
                ),
                ['bet_id' => (int) $bet->getKey(), 'bet_item_count' => $count],
            );
        }
    }

    /**
     * Refuse a new paid wager while a confirmed deposit refund is still unrecovered.
     *
     * `$proveClosure` selects the two halves of the same proof: the early call answers
     * "is there custody at all" (cheap), the late call re-proves every pending row from
     * its recovery history so a completion label alone can never clear the fence.
     */
    private function assertNoRefundExposure(BetPurchaseContext $context, bool $proveClosure): void
    {
        $player = User::query()->find($context->userId());

        if (! $player instanceof User) {
            return;
        }

        $guard = app(StripeRefundWagerGuard::class);

        if ($proveClosure) {
            $guard->assertAllowed($player, $context->currency());
        } else {
            $guard->assertNoPendingExposure($player, $context->currency());
        }
    }

    /**
     * Turn a database error into purchase vocabulary, preserving the original.
     */
    private function classify(QueryException $exception, BetPurchaseContext $context): Throwable
    {
        if ($this->idempotency->isDuplicateKeyViolation($exception)) {
            // A concurrent identical request inserted first. This is a replay signal.
            return BetPurchaseConcurrencyException::idempotentRaceLost(
                $context->betIdempotencyKey,
                $context->userId(),
                0,
                $exception,
            );
        }

        if (BetPurchaseConcurrencyException::isUniqueViolation($exception)) {
            return BetPurchaseConcurrencyException::uniqueViolation(
                'a unique constraint refused a row created by this purchase',
                $exception,
            );
        }

        if (BetPurchaseConcurrencyException::isLockFailure($exception)) {
            return BetPurchaseConcurrencyException::deadlock(
                sprintf(
                    'the purchase could not obtain its locks within %d attempts; nothing was persisted',
                    $this->attempts(),
                ),
                $exception,
            );
        }

        return $exception;
    }

    /**
     * Retry budget for the whole closure, from the Phase 2.1 locking configuration so
     * the money engine and the purchase agree on how much contention is tolerated.
     */
    public function attempts(): int
    {
        $configured = $this->config->get('finance.locking.max_retries');

        if (is_int($configured) && $configured >= 1) {
            return $configured;
        }

        if (is_string($configured) && ctype_digit($configured) && (int) $configured >= 1) {
            return (int) $configured;
        }

        return self::DEFAULT_ATTEMPTS;
    }

    /**
     * A purchase must own its transaction. Running inside somebody else's would mean a
     * failure could be caught and the surrounding transaction committed anyway, which
     * would defeat the atomicity guarantee entirely.
     *
     * @throws BetPurchaseException
     */
    private function assertNotAlreadyInTransaction(): void
    {
        if (DB::transactionLevel() > 0) {
            throw BetPurchaseException::invariantViolated(
                'a bet purchase must own its own database transaction, but one is already open. '
                .'Nesting it would let a caller commit a purchase that failed.',
                ['transaction_level' => DB::transactionLevel()],
            );
        }
    }
}
