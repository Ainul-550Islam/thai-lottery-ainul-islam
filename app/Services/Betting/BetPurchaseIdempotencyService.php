<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetPurchaseData;
use App\DTOs\BetPurchaseResult;
use App\Enums\Currency;
use App\Exceptions\BetDomainException;
use App\Exceptions\BetPurchaseIdempotencyException;
use App\Exceptions\FinancialException;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\NumberLimit;
use App\Models\Ticket;
use App\Services\Finance\IdempotencyService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\ValueObjects\BetAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Request-level idempotency for bet purchases, enforced by the database.
 */
final class BetPurchaseIdempotencyService
{
    /** Digest scope for the key stored on bets.idempotency_key. */
    public const BET_KEY_SCOPE = 'betpurchase';

    /** Digest scope for the key handed to the Phase 2.1 wallet engine. */
    public const FINANCIAL_KEY_SCOPE = 'betdebit';

    /** Shortest client key accepted before derivation. */
    public const MIN_CLIENT_KEY_LENGTH = 8;

    /** Longest client key accepted; matches the width of the database column. */
    public const MAX_CLIENT_KEY_LENGTH = 128;

    private const CLIENT_KEY_PATTERN = '/^[A-Za-z0-9_.:\-]+$/';

    public function __construct(
        private readonly IdempotencyService $idempotency,
    ) {}

    /**
     * @throws BetPurchaseIdempotencyException
     */
    public function assertSchemaSupportsIdempotency(): void
    {
        if (! Schema::hasTable('bets')) {
            throw BetPurchaseIdempotencyException::schemaChangeRequired(
                'the bets table does not exist, so no bet-level idempotency can be enforced',
            );
        }

        if (! Schema::hasColumn('bets', 'idempotency_key')) {
            throw BetPurchaseIdempotencyException::schemaChangeRequired(
                'bets.idempotency_key does not exist. Add a string(128) column with a UNIQUE '
                .'index before enabling bet purchases',
                ['table' => 'bets', 'column' => 'idempotency_key'],
            );
        }

        if (! Schema::hasColumn('financial_transactions', 'idempotency_key')) {
            throw BetPurchaseIdempotencyException::schemaChangeRequired(
                'financial_transactions.idempotency_key does not exist, so the wallet debit '
                .'cannot be made idempotent',
                ['table' => 'financial_transactions', 'column' => 'idempotency_key'],
            );
        }
    }

    /**
     * @throws BetPurchaseIdempotencyException
     */
    public function assertClientKeyUsable(string $clientKey): string
    {
        $key = trim($clientKey);

        if ($key === '') {
            throw BetPurchaseIdempotencyException::keyRequired();
        }

        $length = strlen($key);

        if ($length < self::MIN_CLIENT_KEY_LENGTH || $length > self::MAX_CLIENT_KEY_LENGTH) {
            throw BetPurchaseIdempotencyException::keyInvalid(
                sprintf(
                    'it must be between %d and %d characters long, and this one is %d',
                    self::MIN_CLIENT_KEY_LENGTH,
                    self::MAX_CLIENT_KEY_LENGTH,
                    $length,
                ),
                ['key_length' => $length],
            );
        }

        if (preg_match(self::CLIENT_KEY_PATTERN, $key) !== 1) {
            throw BetPurchaseIdempotencyException::keyInvalid(
                'it may only contain letters, digits and the characters _ . : -',
                ['key_length' => $length],
            );
        }

        return $key;
    }

    /**
     * @throws BetPurchaseIdempotencyException
     */
    public function betKeyFor(BetPurchaseData $data): string
    {
        return $this->derive(self::BET_KEY_SCOPE, $data);
    }

    /**
     * @throws BetPurchaseIdempotencyException
     */
    public function financialKeyFor(BetPurchaseData $data): string
    {
        return $this->derive(self::FINANCIAL_KEY_SCOPE, $data);
    }

    public function findExistingBet(string $betKey): ?Bet
    {
        return Bet::withTrashed()
            ->where('idempotency_key', $betKey)
            ->first();
    }

    /**
     * @throws BetPurchaseIdempotencyException
     */
    public function resolve(string $betKey, BetPurchaseData $data): ?Bet
    {
        $existing = $this->findExistingBet($betKey);

        if (! $existing instanceof Bet) {
            return null;
        }

        if ($existing->trashed()) {
            app(BetPurchaseReceipt::class)->refuse((int) $existing->getKey());
        }

        $mismatched = $this->mismatchedFields($existing, $data);

        if ($mismatched !== []) {
            throw BetPurchaseIdempotencyException::payloadMismatch(
                $betKey,
                $mismatched,
                [
                    'existing_bet_id' => (int) $existing->getKey(),
                    'existing_bet_number' => (string) $existing->bet_number,
                    'request_fingerprint' => $data->fingerprint(),
                ],
            );
        }

        return $existing;
    }

    /**
     * @return list<string>
     */
    public function mismatchedFields(Bet $existing, BetPurchaseData $data): array
    {
        $mismatched = [];

        if ((int) $existing->user_id !== $data->userId) {
            $mismatched[] = 'user_id';
        }

        if ((int) $existing->draw_id !== $data->drawId) {
            $mismatched[] = 'draw_id';
        }

        $storedMarket = $this->storedMarketOf($existing);

        if ($storedMarket !== null && $storedMarket !== $data->marketKey) {
            $mismatched[] = 'market';
        }

        $storedNumber = $this->storedNumberOf($existing);

        if ($storedNumber !== null && ! $this->numberMatches($storedNumber, $data->rawNumber)) {
            $mismatched[] = 'number';
        }

        if (! $this->stakeMatches((string) $existing->stake_amount, $data->rawStake, $this->currencyOf($existing)->scale())) {
            $mismatched[] = 'stake';
        }

        return $mismatched;
    }

    /**
     * Build the replay result for an already committed bet.
     *
     * @throws BetPurchaseIdempotencyException
     * @throws FinancialException
     */
    public function replayResultFor(Bet $bet): BetPurchaseResult
    {
        return DB::transaction(function () use ($bet): BetPurchaseResult {
            $betId = (int) $bet->getKey();
            $claim = DB::table('bet_purchase_ownerships')->where('bet_id', $betId)->first();

            if (! $claim) {
                app(BetPurchaseReceipt::class)->refuse($betId);
            }

            $wallet = app(WalletLockService::class)->lock((int) $claim->wallet_id);
            $lockedBet = Bet::withTrashed()->whereKey($betId)->lockForUpdate()->first();

            if (! $lockedBet instanceof Bet || $lockedBet->trashed()) {
                app(BetPurchaseReceipt::class)->refuse($betId);
            }

            try {
                $purchase = app(BetPurchaseOwnership::class)->funded($lockedBet, $wallet);
                [, , , $item] = app(BetPurchaseOwnership::class)->terms($lockedBet, false);
            } catch (BetDomainException) {
                app(BetPurchaseReceipt::class)->refuse($betId);
            }

            $limit = NumberLimit::query()->whereKey((int) $purchase->number_limit_id)->lockForUpdate()->first();

            if (! $limit instanceof NumberLimit
                || (int) $limit->draw_id !== (int) $lockedBet->draw_id
                || $limit->bet_type !== $lockedBet->type
                || $limit->number !== $item->number) {
                app(BetPurchaseReceipt::class)->refuse($betId);
            }

            $ticketId = $lockedBet->ticket_id;

            if ($ticketId === null) {
                app(BetPurchaseReceipt::class)->refuse($betId);
            }

            $ticket = Ticket::withTrashed()->whereKey((int) $ticketId)->lockForUpdate()->first();
            $transaction = FinancialTransaction::withTrashed()->whereKey((int) $purchase->original_transaction_id)->lockForUpdate()->first();

            if (! $ticket instanceof Ticket || $ticket->trashed()
                || ! $transaction instanceof FinancialTransaction || $transaction->trashed()) {
                app(BetPurchaseReceipt::class)->refuse($betId);
            }

            app(BetPurchaseReceipt::class)->verify($lockedBet, $purchase, $ticket, $transaction);

            $currency = $this->currencyOf($lockedBet);

            return BetPurchaseResult::replayed(
                bet: $lockedBet,
                item: $item,
                ticket: $ticket,
                transaction: $transaction,
                stake: BetAmount::fromDatabase((string) $purchase->stake_amount, $currency),
                potentialPayout: Money::fromDatabase((string) $purchase->potential_payout, $currency),
                idempotencyKey: (string) $lockedBet->idempotency_key,
                ledgerEntryIds: $this->ledgerEntryIdsFor($transaction),
                context: [
                    'replay_of_bet_id' => $betId,
                    'replay_of_transaction_id' => (int) $transaction->getKey(),
                    'proof_scope' => 'original_funding_only',
                ],
            );
        });
    }

    public function debitFor(Bet $bet): ?FinancialTransaction
    {
        return FinancialTransaction::query()
            ->where('reference_type', Bet::class)
            ->where('reference_id', (int) $bet->getKey())
            ->orderBy('id')
            ->first();
    }

    /**
     * @return list<int>
     */
    public function ledgerEntryIdsFor(FinancialTransaction $transaction): array
    {
        return LedgerEntry::query()
            ->where('financial_transaction_id', (int) $transaction->getKey())
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    public function isDuplicateKeyViolation(\Illuminate\Database\QueryException $exception): bool
    {
        return $this->idempotency->isDuplicateKeyViolation($exception);
    }

    /**
     * @throws BetPurchaseIdempotencyException
     */
    private function derive(string $scope, BetPurchaseData $data): string
    {
        $clientKey = $this->assertClientKeyUsable($data->idempotencyKey);

        $identifier = implode('|', [
            (string) $data->userId,
            (string) $data->drawId,
            $clientKey,
        ]);

        try {
            return $this->idempotency->deterministicKey($scope, $identifier);
        } catch (FinancialException $exception) {
            throw BetPurchaseIdempotencyException::keyInvalid(
                'the derived key was refused by the finance idempotency rules',
                ['scope' => $scope],
                $exception,
            );
        }
    }

    private function storedMarketOf(Bet $bet): ?string
    {
        $metadata = $bet->metadata;

        if (! is_array($metadata)) {
            return null;
        }

        $market = $metadata['market'] ?? null;

        return is_string($market) ? $market : null;
    }

    private function storedNumberOf(Bet $bet): ?string
    {
        $number = BetItem::query()
            ->where('bet_id', (int) $bet->getKey())
            ->orderBy('id')
            ->value('number');

        return is_string($number) ? $number : null;
    }

    private function numberMatches(string $stored, string $raw): bool
    {
        if ($stored === $raw) {
            return true;
        }

        if (preg_match('/^\d+$/', $raw) !== 1) {
            return false;
        }

        if (strlen($raw) > strlen($stored)) {
            return false;
        }

        return $stored === str_pad($raw, strlen($stored), '0', STR_PAD_LEFT);
    }

    private function stakeMatches(string $stored, string $raw, int $scale): bool
    {
        if (preg_match('/\A\d+(?:\.\d{1,2})?\z/D', $raw) !== 1 || preg_match('/\A\d+(?:\.\d{1,2})?\z/D', $stored) !== 1) {
            return false;
        }

        if (bccomp($raw, '0', $scale) <= 0 || bccomp($stored, '0', $scale) <= 0) {
            return false;
        }

        return bccomp($stored, $raw, $scale) === 0;
    }

    private function currencyOf(Bet $bet): Currency
    {
        return $bet->currency instanceof Currency
            ? $bet->currency
            : Currency::from((string) $bet->currency);
    }
}
