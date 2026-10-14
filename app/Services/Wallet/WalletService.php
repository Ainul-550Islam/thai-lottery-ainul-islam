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
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Wallet Service Facade / Delegation Layer.
 *
 * Provides convenient high-level API over the canonical double-entry
 * App\Services\Finance\WalletService engine while maintaining exact decimal
 * arithmetic, concurrency locking, and double-entry ledger postings.
 *
 * The canonical engine is strict: it takes a resolved Wallet model, an exact
 * Money value and a FinancialTransactionType enum. This facade is the
 * convenience boundary, so it is the one place that turns the looser inputs
 * (a wallet id, a decimal string, a free-text label) into those types. Doing
 * the conversion here, rather than in every caller, is what keeps the strict
 * engine from being reachable through a TypeError on ordinary input.
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
     * A string $type that names a genuine transaction type (e.g. "deposit") is
     * used as that type. Any other string is a human description: it is recorded
     * as the description against an Adjustment. It is never blanket-assumed to be
     * a Deposit, because "Initial funds" on the ledger as a Deposit would assert
     * that external money arrived when the caller held only a sentence.
     *
     * A plain string in the $options position is shorthand for the description.
     *
     * @param  array<string, mixed>|string  $options
     */
    public function credit(
        Wallet|int $wallet,
        Money|string $amount,
        string|FinancialTransactionType $type = FinancialTransactionType::Deposit,
        ?string $idempotencyKey = null,
        array|string $options = [],
    ): FinancialTransaction {
        $wallet = $this->resolveWallet($wallet);
        [$resolvedType, $options] = $this->resolveType($type, $this->normaliseOptions($options));

        return $this->canonical->credit(
            $wallet,
            $this->resolveAmount($amount, $wallet),
            $resolvedType,
            $idempotencyKey,
            $options,
        );
    }

    /**
     * Debit a wallet with an exact decimal amount and ledger entry.
     *
     * Type resolution follows credit(). A free-text label is recorded as the
     * description against an Adjustment rather than being assumed to be a
     * Withdrawal.
     *
     * A plain string in the $options position is shorthand for the description.
     *
     * @param  array<string, mixed>|string  $options
     */
    public function debit(
        Wallet|int $wallet,
        Money|string $amount,
        string|FinancialTransactionType $type = FinancialTransactionType::Withdrawal,
        ?string $idempotencyKey = null,
        array|string $options = [],
    ): FinancialTransaction {
        $wallet = $this->resolveWallet($wallet);
        [$resolvedType, $options] = $this->resolveType($type, $this->normaliseOptions($options));

        return $this->canonical->debit(
            $wallet,
            $this->resolveAmount($amount, $wallet),
            $resolvedType,
            $idempotencyKey,
            $options,
        );
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

    /**
     * Accept either a loaded wallet or its primary key.
     *
     * @throws ModelNotFoundException when an id does not name a wallet
     */
    private function resolveWallet(Wallet|int $wallet): Wallet
    {
        if ($wallet instanceof Wallet) {
            return $wallet;
        }

        return Wallet::query()->findOrFail($wallet);
    }

    /**
     * Turn a decimal string into exact Money in the wallet's own currency.
     * A Money instance is passed through unchanged.
     */
    private function resolveAmount(Money|string $amount, Wallet $wallet): Money
    {
        return $amount instanceof Money
            ? $amount
            : Money::of($amount, $wallet->currency);
    }

    /**
     * @param  array<string, mixed>|string  $options
     * @return array<string, mixed>
     */
    private function normaliseOptions(array|string $options): array
    {
        if (is_string($options)) {
            return ['description' => trim($options)];
        }

        return $options;
    }

    /**
     * Map a caller's type onto the canonical enum.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: FinancialTransactionType, 1: array<string, mixed>}
     */
    private function resolveType(string|FinancialTransactionType $type, array $options): array
    {
        if ($type instanceof FinancialTransactionType) {
            return [$type, $options];
        }

        $genuine = FinancialTransactionType::tryFrom(strtolower(trim($type)));

        if ($genuine !== null) {
            return [$genuine, $options];
        }

        // A free-text label: keep it as the human description on an adjustment.
        if (! isset($options['description']) || ! is_string($options['description'])) {
            $options['description'] = trim($type);
        }

        return [FinancialTransactionType::Adjustment, $options];
    }
}
