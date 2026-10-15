<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\WalletType;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService as CanonicalWalletService;
use Illuminate\Contracts\Container\Container;

/**
 * Wallet Service Facade / Delegation Layer.
 *
 * Provides convenient high-level API over the canonical double-entry
 * App\Services\Finance\WalletService engine while maintaining exact decimal
 * arithmetic, concurrency locking, and double-entry ledger postings.
 */
class WalletService
{
    private CanonicalWalletService $canonical;

    public function __construct(
        private readonly Container $container,
        private readonly WalletLockService $locks,
    ) {
        $this->canonical = $this->container->make(CanonicalWalletService::class);
    }

    /**
     * Resolve or initialize the canonical wallet for a user and currency.
     */
    public function getOrCreateWallet(int|User $user, string|Currency $currency = 'THB'): Wallet
    {
        $userId = $user instanceof User ? (int) $user->getKey() : (int) $user;
        $currencyEnum = is_string($currency) ? Currency::from(strtoupper($currency)) : $currency;

        // BLOCKER CLOSURE: see App\Services\Finance\WalletService::getOrCreateWallet().
        // Creation invariants (status, zeroed money columns, version = 1) are
        // owned by Wallet::booted() so both wallet services cannot diverge.
        return Wallet::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'currency' => $currencyEnum,
                'type' => WalletType::Primary,
            ]
        );
    }

    /**
     * Credit a wallet with an exact decimal amount and ledger entry.
     *
     * A string $type is passed through to the canonical engine, which resolves
     * a genuine transaction type by name and records anything else as a
     * description against an Adjustment. It is never blanket-assumed to be a
     * Deposit here: "Initial funds" on the ledger as a Deposit would assert
     * that external money arrived when the caller held only a sentence.
     */
    public function credit(
        Wallet|int $wallet,
        Money|string $amount,
        string|FinancialTransactionType $type = FinancialTransactionType::Deposit,
        ?string $idempotencyKey = null,
        array $options = [],
    ): FinancialTransaction {
        return $this->canonical->credit($wallet, $amount, $type, $idempotencyKey, $options);
    }

    /**
     * Debit a wallet with an exact decimal amount and ledger entry.
     */
    public function debit(
        Wallet|int $wallet,
        Money|string $amount,
        string|FinancialTransactionType $type = FinancialTransactionType::Withdrawal,
        ?string $idempotencyKey = null,
        array $options = [],
    ): FinancialTransaction {
        return $this->canonical->debit($wallet, $amount, $type, $idempotencyKey, $options);
    }

    /**
     * Reserve funds in a wallet.
     */
    public function lockFunds(Wallet $wallet, Money|string $amount, ?string $reason = null): Wallet
    {
        $moneyObj = is_string($amount) ? Money::of($amount, $wallet->currency) : $amount;

        return $this->canonical->lockFunds($wallet, $moneyObj, $reason);
    }

    /**
     * Release reserved funds in a wallet.
     */
    public function unlockFunds(Wallet $wallet, Money|string $amount): Wallet
    {
        $moneyObj = is_string($amount) ? Money::of($amount, $wallet->currency) : $amount;

        return $this->canonical->unlockFunds($wallet, $moneyObj);
    }
}
