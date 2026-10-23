<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\WithdrawalException;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Payment\WithdrawalProviderReferenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only place a withdrawal actually takes money out of a wallet.
 */
final class WithdrawalCompletionService
{
    /** Scope used to derive the debit idempotency key. */
    private const IDEMPOTENCY_SCOPE = 'withdrawal-debit';

    public function __construct(
        private readonly WalletLockService $locks,
        private readonly WalletService $wallets,
        private readonly WalletHoldService $holds,
        private readonly IdempotencyService $idempotency,
        private readonly FinancialStateTransitionService $transitions,
    ) {}

    /**
     * Settle an approved withdrawal: consume the reservation and debit the wallet.
     *
     * @param  array<string, mixed>  $options  description, metadata, provider, provider_reference
     * @return array{withdrawal: Withdrawal, transaction: FinancialTransaction, wallet: Wallet, debited: bool, replayed: bool, amount: string, snapshot: array{balance: string, available_balance: string, locked_balance: string, currency: string, hold_type: string|null}}
     *
     * @throws WithdrawalException
     * @throws FinancialException
     */
    public function complete(Withdrawal $withdrawal, ?string $idempotencyKey = null, array $options = []): array
    {
        Money::assertExactArithmeticIsAvailable();

        return $this->withinTransaction(function () use ($withdrawal, $options): array {
            $wallet = $this->locks->lock((int) $withdrawal->wallet_id);
            $current = $this->transitions->lockWithdrawal($withdrawal);

            $this->assertWalletMatches($current, $wallet);

            if ($current->status === WithdrawalStatus::Completed) {
                return $this->replayResult($current, $wallet);
            }

            if ($current->financial_transaction_id !== null) {
                app(WithdrawalIntegrityGuard::class)->refuse();
            }

            if (! $current->status->canComplete()) {
                throw WithdrawalException::notCompletable($current->id, $current->status);
            }

            $this->assertCurrencyMatches($current, $wallet);

            if (! $wallet->canDebit()) {
                throw WithdrawalException::walletNotDebitable((int) $wallet->getKey(), $wallet->status->value);
            }

            $amount = $this->amountOf($current);
            $reserved = $this->holds->heldAmount($wallet);

            if ($reserved->isLessThan($amount)) {
                throw WithdrawalException::noReservation(
                    (int) $current->getKey(),
                    (int) $wallet->getKey(),
                    $amount->toString(),
                    $reserved->toString(),
                );
            }

            $provider = $this->stringOption($options, 'provider') ?? $current->provider;
            $providerReference = $this->stringOption($options, 'provider_reference') ?? $current->provider_reference;

            if ($providerReference !== null) {
                $payment = Payment::query()
                    ->where('payable_type', Withdrawal::class)
                    ->where('payable_id', $current->id)
                    ->lockForUpdate()
                    ->first();

                app(WithdrawalProviderReferenceService::class)->bind(
                    $current,
                    $provider ?? $current->method->value,
                    $providerReference,
                    $payment,
                );

                $current->provider = $provider ?? $current->provider;
                $current->provider_reference = $providerReference;
                $current->save();
            }

            $transaction = app(WithdrawalReservationService::class)->settle($current, $wallet, $options);

            $now = Carbon::now();

            $completed = $this->transitions->transitionWithdrawal($current, WithdrawalStatus::Completed, [
                'financial_transaction_id' => (int) $transaction->getKey(),
                'completed_at' => $now,
                'provider' => $provider,
                'provider_reference' => $providerReference,
                'metadata' => $this->mergeMetadata($current, [
                    'completed_at' => $now->toIso8601String(),
                    'debited_amount' => $amount->toString(),
                    'financial_transaction_reference' => $transaction->reference_number,
                    'reconciliation_required' => false,
                    'disbursement_outcome' => 'completed',
                ]),
            ]);

            $finalWallet = $this->locks->relock($wallet);

            $this->holds->assertInvariant($finalWallet);
            $this->assertSettled($completed);
            $this->verifySettled($completed);

            return [
                'withdrawal' => $completed,
                'transaction' => $transaction,
                'wallet' => $finalWallet,
                'debited' => true,
                'replayed' => false,
                'amount' => $amount->toString(),
                'snapshot' => $this->holds->snapshot($finalWallet),
            ];
        });
    }

    /**
     * The amount debited from the wallet: the gross request amount.
     */
    public function amountOf(Withdrawal $withdrawal): Money
    {
        return Money::fromDatabase((string) $withdrawal->amount, $withdrawal->currency);
    }

    /**
     * The deterministic debit key for a withdrawal.
     */
    public function idempotencyKeyFor(Withdrawal $withdrawal): string
    {
        return $this->idempotency->deterministicKey(
            self::IDEMPOTENCY_SCOPE,
            (string) $withdrawal->getKey(),
        );
    }

    public function existingTransactionFor(Withdrawal $withdrawal): ?FinancialTransaction
    {
        if ($withdrawal->financial_transaction_id !== null) {
            return FinancialTransaction::query()->whereKey($withdrawal->financial_transaction_id)->first();
        }

        return $this->idempotency->existingFor($this->idempotencyKeyFor($withdrawal));
    }

    /**
     * Whether the payout may be settled right now, without throwing.
     */
    public function canComplete(Withdrawal $withdrawal): bool
    {
        return $withdrawal->status->canComplete() && $withdrawal->financial_transaction_id === null;
    }

    public function verifySettled(Withdrawal $withdrawal): FinancialTransaction
    {
        if ($withdrawal->status !== WithdrawalStatus::Completed
            || $withdrawal->financial_transaction_id === null
            || $withdrawal->completed_at === null) {
            app(WithdrawalIntegrityGuard::class)->refuse();
        }

        $wallet = Wallet::withTrashed()->find($withdrawal->wallet_id);
        $transaction = FinancialTransaction::withTrashed()->find($withdrawal->financial_transaction_id);

        if (! $wallet instanceof Wallet || ! $transaction instanceof FinancialTransaction) {
            app(WithdrawalIntegrityGuard::class)->refuse();
        }

        app(WithdrawalIntegrityGuard::class)->assertProof(
            $withdrawal,
            $wallet,
            $transaction,
            $this->idempotencyKeyFor($withdrawal),
        );

        return $transaction;
    }

    public function isSettled(Withdrawal $withdrawal): bool
    {
        try {
            $this->verifySettled($withdrawal);

            return true;
        } catch (FinancialException) {
            return false;
        }
    }

    /**
     * Report an already-settled withdrawal without moving money.
     *
     * @return array{withdrawal: Withdrawal, transaction: FinancialTransaction, wallet: Wallet, debited: bool, replayed: bool, amount: string, snapshot: array{balance: string, available_balance: string, locked_balance: string, currency: string, hold_type: string|null}}
     *
     * @throws WithdrawalException
     */
    private function replayResult(Withdrawal $withdrawal, Wallet $wallet): array
    {
        $transaction = $this->verifySettled($withdrawal);

        return [
            'withdrawal' => $withdrawal,
            'transaction' => $transaction,
            'wallet' => $wallet,
            'debited' => false,
            'replayed' => true,
            'amount' => Money::fromDatabase((string) $transaction->amount, $withdrawal->currency)->toString(),
            'snapshot' => $this->holds->snapshot($wallet),
        ];
    }

    /**
     * A completed withdrawal must always carry its transaction link and timestamp.
     *
     * @throws WithdrawalException
     */
    private function assertSettled(Withdrawal $withdrawal): void
    {
        if ($withdrawal->financial_transaction_id !== null && $withdrawal->completed_at !== null) {
            return;
        }

        throw WithdrawalException::inconsistentState(
            (int) $withdrawal->getKey(),
            'the withdrawal was marked completed without a linked transaction or completion timestamp',
        );
    }

    /**
     * @throws WithdrawalException
     */
    private function assertCurrencyMatches(Withdrawal $withdrawal, Wallet $wallet): void
    {
        if ($withdrawal->currency === $wallet->currency) {
            return;
        }

        throw WithdrawalException::currencyMismatch(
            (int) $wallet->getKey(),
            $wallet->currency,
            $withdrawal->currency,
        );
    }

    /**
     * @throws WithdrawalException
     */
    private function assertWalletMatches(Withdrawal $withdrawal, Wallet $wallet): void
    {
        if ((int) $withdrawal->wallet_id === (int) $wallet->getKey()) {
            return;
        }

        throw WithdrawalException::walletMismatch(
            (int) $withdrawal->getKey(),
            (int) $withdrawal->wallet_id,
            (int) $wallet->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $additions
     * @return array<string, mixed>
     */
    private function mergeMetadata(Withdrawal $withdrawal, array $additions): array
    {
        $existing = is_array($withdrawal->metadata) ? $withdrawal->metadata : [];

        return array_merge($existing, $additions);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function stringOption(array $options, string $key): ?string
    {
        return isset($options[$key]) && is_string($options[$key]) && $options[$key] !== ''
            ? $options[$key]
            : null;
    }

    private function withinTransaction(\Closure $callback): mixed
    {
        return DB::transaction($callback);
    }
}
