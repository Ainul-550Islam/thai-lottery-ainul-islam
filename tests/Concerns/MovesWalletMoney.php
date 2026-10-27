<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;

/**
 * Test-side adapter onto the canonical WalletService money path.
 *
 * Fixtures state an amount in the wallet's own currency, a description, and optionally an
 * idempotency key and a note. Every movement still goes through WalletService::credit() or
 * ::debit(), so ledger postings, locks and balance invariants are the production ones.
 */
trait MovesWalletMoney
{
    protected function creditWallet(
        Wallet|int $wallet,
        string $amount,
        string $description,
        ?string $idempotencyKey = null,
        ?string $note = null,
    ): void {
        $this->moveWalletMoney($wallet, $amount, $description, $idempotencyKey, $note, true);
    }

    protected function debitWallet(
        Wallet|int $wallet,
        string $amount,
        string $description,
        ?string $idempotencyKey = null,
        ?string $note = null,
    ): void {
        $this->moveWalletMoney($wallet, $amount, $description, $idempotencyKey, $note, false);
    }

    private function moveWalletMoney(
        Wallet|int $wallet,
        string $amount,
        string $description,
        ?string $idempotencyKey,
        ?string $note,
        bool $credit,
    ): void {
        $resolved = $wallet instanceof Wallet ? $wallet : Wallet::query()->findOrFail($wallet);
        $currency = $resolved->currency instanceof Currency
            ? $resolved->currency
            : Currency::from((string) $resolved->currency);
        $options = ['description' => $description];

        if ($note !== null) {
            $options['metadata'] = ['note' => $note];
        }

        $service = app(WalletService::class);
        $money = Money::of($amount, $currency);
        $key = $this->fixtureIdempotencyKey($idempotencyKey, $resolved, $description, $amount, $credit);

        if ($credit) {
            $service->credit($resolved, $money, FinancialTransactionType::Deposit, $key, $options);

            return;
        }

        $service->debit($resolved, $money, FinancialTransactionType::Withdrawal, $key, $options);
    }

    /**
     * Keys must be 16 to 128 characters. A short fixture key is widened deterministically, so
     * the same fixture always reuses the same key and a repeated call is still an idempotent
     * replay. A missing key is derived from the movement itself.
     */
    private function fixtureIdempotencyKey(
        ?string $key,
        Wallet $wallet,
        string $description,
        string $amount,
        bool $credit,
    ): string {
        if ($key !== null && strlen($key) >= 16 && strlen($key) <= 128) {
            return $key;
        }

        if ($key === null) {
            return 'fixture-'.bin2hex(random_bytes(16));
        }

        return 'fixture-'.hash('sha256', implode('|', [$wallet->getKey(), $key, $description, $amount, $credit ? 'credit' : 'debit']));
    }
}
