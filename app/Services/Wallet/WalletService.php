<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Exceptions\FinancialException;
use App\Exceptions\InsufficientBalanceException;
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

        return Wallet::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'currency' => $currencyEnum,
                'type' => \App\Enums\WalletType::Primary,
            ],
            [
                'status' => \App\Enums\WalletStatus::Active,
                'balance' => '0.00',
                'locked_balance' => '0.00',
                'total_deposited' => '0.00',
                'total_withdrawn' => '0.00',
                'total_wagered' => '0.00',
                'total_won' => '0.00',
                'version' => 1,
            ]
        );
    }

    /**
     * Credit a wallet with an exact decimal amount and ledger entry.
     */
    public function credit(
        Wallet|int $wallet,
        Money|string $amount,
        string|FinancialTransactionType $type = FinancialTransactionType::Deposit,
        ?string $idempotencyKey = null,
        array $options = [],
    ): FinancialTransaction {
        $walletModel = is_int($wallet) ? Wallet::query()->findOrFail($wallet) : $wallet;
        $moneyObj = is_string($amount) ? Money::of($amount, $walletModel->currency) : $amount;
        $typeEnum = is_string($type) ? FinancialTransactionType::Deposit : $type;

        return $this->canonical->credit($walletModel, $moneyObj, $typeEnum, $idempotencyKey, $options);
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
        $walletModel = is_int($wallet) ? Wallet::query()->findOrFail($wallet) : $wallet;
        $moneyObj = is_string($amount) ? Money::of($amount, $walletModel->currency) : $amount;
        $typeEnum = is_string($type) ? FinancialTransactionType::Withdrawal : $type;

        return $this->canonical->debit($walletModel, $moneyObj, $typeEnum, $idempotencyKey, $options);
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
