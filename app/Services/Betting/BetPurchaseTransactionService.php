<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetPurchaseContext;
use App\DTOs\BetPurchaseResult;
use App\Exceptions\BetPurchaseConcurrencyException;
use App\Exceptions\BetPurchaseException;
use App\Models\Bet;
use App\Services\Agent\AgentCommissionAccrualService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Owns the single database transaction in which a bet purchase becomes real.
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
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Execute the whole purchase atomically.
     *
     * @throws BetPurchaseConcurrencyException when a concurrent identical request won,
     *                                         or when contention could not be resolved
     * @throws BetPurchaseException on any refusal; nothing is persisted
     */
    public function execute(BetPurchaseContext $context, ?BetAmendmentPermit $permit = null): BetPurchaseResult
    {
        $this->assertNotAlreadyInTransaction();

        $maxAttempts = $this->attempts();
        $delayMs = max(0, (int) $this->config->get('finance.locking.retry_delay_milliseconds', 25));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return DB::transaction(
                    fn (): BetPurchaseResult => $this->purchase($context, $permit),
                    1,
                );
            } catch (QueryException $exception) {
                if (BetPurchaseConcurrencyException::isLockFailure($exception) && $attempt < $maxAttempts) {
                    if ($delayMs > 0) {
                        usleep($delayMs * 1000);
                    }

                    continue;
                }

                throw $this->classify($exception, $context);
            }
        }

        throw BetPurchaseConcurrencyException::deadlock('purchase bet');
    }

    /**
     * The purchase body. Runs entirely inside the transaction.
     *
     * @throws BetPurchaseConcurrencyException
     * @throws BetPurchaseException
     */
    private function purchase(BetPurchaseContext $context, ?BetAmendmentPermit $permit = null): BetPurchaseResult
    {
        // 1. Wallet row lock. First lock on every path.
        $wallet = $this->wallets->lockForDebit($context->walletId(), $context->currency());

        // 2. Idempotency, re-checked while holding the lock.
        $existing = $this->bets->lockByIdempotencyKey($context->betIdempotencyKey);

        if ($existing instanceof Bet) {
            throw BetPurchaseConcurrencyException::idempotentRaceLost(
                $context->betIdempotencyKey,
                $context->userId(),
                (int) $existing->getKey(),
            );
        }

        // 3. Re-evaluate player, wallet, and responsible-gaming admission under lock.
        app(BetPurchaseAdmission::class)->assertAllowed($context, $wallet);
        app(StripeRefundWagerGuard::class)->assertNoPendingExposure($context->user, $wallet->currency);

        // 4. Affordability, against the locked row.
        $this->wallets->assertSufficientBalance($wallet, $context->stakeMoney());

        // 5. Risk capacity, under the number_limits row lock. No bypass exists.
        $reservation = $this->risk->reserve($context);

        // 6. Verify deposit refund exposure after risk lock and before any money write.
        app(StripeRefundWagerGuard::class)->assertAllowed($context->user, $wallet->currency);

        // 7. The bet.
        $bet = $this->bets->create($context);

        // 8. The one item line.
        $item = $this->items->create($bet, $context);

        // 9. The ticket, then the link.
        $ticket = $this->tickets->create($context);
        $bet = $this->bets->attachTicket($bet, $ticket);

        // 10. The money. Phase 2.1 debits, posts the ledger and asserts its own balance.
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

        // 11. The books, proven before the commit is allowed.
        $ledgerEntryIds = $this->ledger->assertPostedForBet($transaction, $bet);

        // 12. Promotion. Only now is the purchase paid for and recorded.
        $bet = $this->bets->markPlaced($bet);
        $ticket = $this->tickets->confirm($ticket);

        $this->assertExactlyOneItem($bet, $context);

        // 13. Agent commissions accrue on real, paid turnover.
        $this->commissionAccrual->accrueForBet($bet);

        // 14. Record purchase ownership, replay receipt, and optional amendment replacement link.
        app(BetPurchaseOwnership::class)->record($bet, $wallet, $transaction, $reservation);
        app(BetPurchaseReceipt::class)->record($bet, $wallet, $transaction, $ticket);
        app(BetAmendmentOwnership::class)->captureReplacement($bet, $wallet, $transaction, $permit);

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
     * Turn a database error into purchase vocabulary, preserving the original.
     */
    private function classify(QueryException $exception, BetPurchaseContext $context): Throwable
    {
        if ($this->idempotency->isDuplicateKeyViolation($exception)) {
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
