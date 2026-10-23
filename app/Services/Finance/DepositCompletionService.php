<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\DepositStatus;
use App\Enums\FinancialTransactionType;
use App\Exceptions\DepositException;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\Wallet;
use App\Services\Payment\DepositProviderAccountService;
use App\Services\Payment\FinancialProviderReferenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only place a deposit is turned into wallet balance.
 */
final class DepositCompletionService
{
    /** Scope used to derive the credit idempotency key. */
    private const IDEMPOTENCY_SCOPE = 'deposit-credit';

    public function __construct(
        private readonly WalletLockService $locks,
        private readonly WalletService $wallets,
        private readonly IdempotencyService $idempotency,
        private readonly FinancialStateTransitionService $transitions,
    ) {}

    /**
     * Settle an approved deposit into the wallet.
     *
     * @param  array<string, mixed>  $options  description, metadata, provider, provider_reference
     * @return array{deposit: Deposit, transaction: FinancialTransaction, wallet: Wallet, credited: bool, replayed: bool, amount: string}
     *
     * @throws DepositException
     * @throws FinancialException
     */
    public function complete(Deposit $deposit, ?string $idempotencyKey = null, array $options = []): array
    {
        Money::assertExactArithmeticIsAvailable();

        return $this->withinTransaction(function () use ($deposit, $idempotencyKey, $options): array {
            // 1. WALLET lock first.
            $wallet = $this->locks->lock((int) $deposit->wallet_id);

            // 2. Player mutex second.
            app(DepositAdmission::class)->lockPlayer($wallet);

            // 3. FINANCIAL ENTITY lock third.
            $current = $this->transitions->lockDeposit($deposit);

            $this->assertWalletMatches($current, $wallet);

            $guard = app(DepositIntegrityGuard::class);
            $guard->assertTerms($current, $wallet);

            $canonicalKey = $this->idempotencyKeyFor($current);
            if ($idempotencyKey !== null && $this->idempotency->normaliseKey($idempotencyKey) !== $canonicalKey) {
                $guard->refuse();
            }

            // Already settled: report the replay after verifying canonical proof, credit nothing.
            if ($current->status === DepositStatus::Confirmed) {
                return $this->replayResult($current, $wallet, $options);
            }

            // Credited but not yet marked.
            if ($current->financial_transaction_id !== null) {
                return $this->healUnmarkedDeposit($current, $wallet, $options);
            }

            if (! $current->status->canComplete()) {
                throw DepositException::notCompletable($current->id, $current->status);
            }

            $this->assertWalletCanReceive($current, $wallet);
            app(DepositAdmission::class)->assertAllowed($wallet, (string) $current->amount, (int) $current->getKey());

            [$provider, $reference] = $guard->reference($current, $options);
            $scope = app(DepositProviderAccountService::class)->assertActive($current);
            $payment = $guard->payment($current, $provider, $reference, true);
            app(FinancialProviderReferenceService::class)->bind($current, $provider, $scope, $reference, $payment);

            $amount = $this->creditableAmount($current);

            $transaction = $this->wallets->credit(
                wallet: $wallet,
                amount: $amount,
                type: FinancialTransactionType::Deposit,
                idempotencyKey: $canonicalKey,
                options: [
                    'description' => isset($options['description']) && is_string($options['description'])
                        ? $options['description']
                        : sprintf('Deposit %s settled', $current->reference_number),
                    'reference_type' => Deposit::class,
                    'reference_id' => (int) $current->getKey(),
                    'metadata' => array_replace(
                        isset($options['metadata']) && is_array($options['metadata']) ? $options['metadata'] : [],
                        [
                            'deposit_id' => (int) $current->getKey(),
                            'deposit_reference' => $current->reference_number,
                            'deposit_gross_amount' => (string) $current->amount,
                            'deposit_fee' => (string) $current->fee,
                        ],
                    ),
                ],
            );

            $guard->assertProof($current, $wallet, $transaction, $canonicalKey);

            $confirmed = $this->transitions->transitionDeposit($current, DepositStatus::Confirmed, [
                'financial_transaction_id' => (int) $transaction->getKey(),
                'confirmed_at' => Carbon::now(),
                'provider' => $provider,
                'provider_reference' => $reference,
                'metadata' => $this->mergeMetadata($current, [
                    'completed_at' => Carbon::now()->toIso8601String(),
                    'credited_amount' => $amount->toString(),
                    'financial_transaction_reference' => $transaction->reference_number,
                ]),
            ]);

            $freshWallet = $this->locks->relock($wallet);

            $this->assertCredited($confirmed);

            return [
                'deposit' => $confirmed,
                'transaction' => $transaction,
                'wallet' => $freshWallet,
                'credited' => true,
                'replayed' => false,
                'amount' => $amount->toString(),
            ];
        });
    }

    /**
     * Prove that a confirmed deposit has intact original terms, provider binding,
     * and a balanced canonical financial transaction.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws FinancialException
     */
    public function verifySettled(Deposit $row, array $options = []): FinancialTransaction
    {
        $guard = app(DepositIntegrityGuard::class);
        $lock = DB::transactionLevel() > 0;

        /** @var Wallet|null $wallet */
        $wallet = Wallet::withTrashed()
            ->when($lock, static fn ($q) => $q->lockForUpdate())
            ->find($row->wallet_id);

        /** @var Deposit|null $current */
        $current = Deposit::withTrashed()
            ->when($lock, static fn ($q) => $q->lockForUpdate())
            ->find($row->id);

        if (! $wallet instanceof Wallet
            || ! $current instanceof Deposit
            || $current->status !== DepositStatus::Confirmed
            || $current->confirmed_at === null
            || $current->financial_transaction_id === null) {
            $guard->refuse();
        }

        $this->assertWalletMatches($current, $wallet);
        [$provider, $reference] = $guard->reference($current, $options);
        $scope = app(DepositProviderAccountService::class)->stored($current);
        $payment = $guard->payment($current, $provider, $reference, $lock);
        app(FinancialProviderReferenceService::class)->assertBound($current, $provider, $scope, $reference, $payment);

        /** @var FinancialTransaction|null $tx */
        $tx = FinancialTransaction::withTrashed()
            ->when($lock, static fn ($q) => $q->lockForUpdate())
            ->find($current->financial_transaction_id);

        if (! $tx instanceof FinancialTransaction) {
            $guard->refuse();
        }

        $guard->assertProof($current, $wallet, $tx, $this->idempotencyKeyFor($current));

        return $tx;
    }

    /**
     * @throws DepositException
     */
    public function creditableAmount(Deposit $deposit): Money
    {
        $net = Money::fromDatabase((string) $deposit->net_amount, $deposit->currency);

        if (! $net->isPositive()) {
            throw DepositException::netAmountInvalid(
                (string) $deposit->amount,
                (string) $deposit->fee,
                $net->toString(),
            );
        }

        return $net;
    }

    public function idempotencyKeyFor(Deposit $deposit): string
    {
        return $this->idempotency->deterministicKey(
            self::IDEMPOTENCY_SCOPE,
            (string) $deposit->getKey(),
        );
    }

    public function existingTransactionFor(Deposit $deposit): ?FinancialTransaction
    {
        if ($deposit->financial_transaction_id !== null) {
            return FinancialTransaction::query()->whereKey($deposit->financial_transaction_id)->first();
        }

        return $this->idempotency->existingFor($this->idempotencyKeyFor($deposit));
    }

    public function canComplete(Deposit $deposit): bool
    {
        return $deposit->status->canComplete() && $deposit->financial_transaction_id === null;
    }

    public function isSettled(Deposit $deposit): bool
    {
        return $deposit->status === DepositStatus::Confirmed && $deposit->financial_transaction_id !== null;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{deposit: Deposit, transaction: FinancialTransaction, wallet: Wallet, credited: bool, replayed: bool, amount: string}
     *
     * @throws FinancialException
     */
    private function replayResult(Deposit $deposit, Wallet $wallet, array $options = []): array
    {
        $transaction = $this->verifySettled($deposit, $options);

        return [
            'deposit' => $deposit,
            'transaction' => $transaction,
            'wallet' => $wallet,
            'credited' => false,
            'replayed' => true,
            'amount' => Money::fromDatabase((string) $transaction->amount, $deposit->currency)->toString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{deposit: Deposit, transaction: FinancialTransaction, wallet: Wallet, credited: bool, replayed: bool, amount: string}
     *
     * @throws DepositException
     * @throws FinancialException
     */
    private function healUnmarkedDeposit(Deposit $deposit, Wallet $wallet, array $options = []): array
    {
        $guard = app(DepositIntegrityGuard::class);

        /** @var FinancialTransaction|null $transaction */
        $transaction = FinancialTransaction::withTrashed()->lockForUpdate()->find($deposit->financial_transaction_id);

        if (! $transaction instanceof FinancialTransaction) {
            $guard->refuse();
        }

        $guard->assertProof($deposit, $wallet, $transaction, $this->idempotencyKeyFor($deposit));

        [$provider, $reference] = $guard->reference($deposit, $options);
        $scope = app(DepositProviderAccountService::class)->stored($deposit);
        $payment = $guard->payment($deposit, $provider, $reference, true);
        app(FinancialProviderReferenceService::class)->assertBound($deposit, $provider, $scope, $reference, $payment);

        if (! $deposit->status->canTransitionTo(DepositStatus::Confirmed)) {
            throw DepositException::notCompletable($deposit->id, $deposit->status);
        }

        $confirmed = $this->transitions->transitionDeposit($deposit, DepositStatus::Confirmed, [
            'confirmed_at' => $deposit->confirmed_at ?? Carbon::now(),
            'provider' => $provider,
            'provider_reference' => $reference,
            'metadata' => $this->mergeMetadata($deposit, [
                'reconciled_at' => Carbon::now()->toIso8601String(),
                'reconciliation_note' => 'Status completed from an existing credit transaction; no new credit was made.',
            ]),
        ]);

        return [
            'deposit' => $confirmed,
            'transaction' => $transaction,
            'wallet' => $wallet,
            'credited' => false,
            'replayed' => true,
            'amount' => Money::fromDatabase((string) $transaction->amount, $deposit->currency)->toString(),
        ];
    }

    /**
     * @throws DepositException
     */
    private function assertCredited(Deposit $deposit): void
    {
        if ($deposit->financial_transaction_id !== null && $deposit->confirmed_at !== null) {
            return;
        }

        throw DepositException::inconsistentState(
            (int) $deposit->getKey(),
            'the deposit was marked confirmed without a linked transaction or confirmation timestamp',
        );
    }

    /**
     * @throws DepositException
     */
    private function assertWalletCanReceive(Deposit $deposit, Wallet $wallet): void
    {
        if ($deposit->currency !== $wallet->currency) {
            throw DepositException::currencyMismatch(
                (int) $wallet->getKey(),
                $wallet->currency,
                $deposit->currency,
            );
        }

        if (! $wallet->canCredit()) {
            throw DepositException::walletNotCreditable((int) $wallet->getKey(), $wallet->status->value);
        }
    }

    /**
     * @throws DepositException
     */
    private function assertWalletMatches(Deposit $deposit, Wallet $wallet): void
    {
        if ((int) $deposit->wallet_id === (int) $wallet->getKey()) {
            return;
        }

        throw DepositException::walletMismatch(
            (int) $deposit->getKey(),
            (int) $deposit->wallet_id,
            (int) $wallet->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $additions
     * @return array<string, mixed>
     */
    private function mergeMetadata(Deposit $deposit, array $additions): array
    {
        $existing = is_array($deposit->metadata) ? $deposit->metadata : [];

        return array_merge($existing, $additions);
    }

    private function withinTransaction(\Closure $callback): mixed
    {
        return $this->locks->withinTransaction($callback);
    }
}
