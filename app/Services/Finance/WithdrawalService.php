<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Currency;
use App\Enums\FinancialReferenceType;
use App\Enums\PaymentMethod;
use App\Enums\WalletHoldType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\WithdrawalException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Compliance\WithdrawalKycGateService as ComplianceKycGate;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Payment\WithdrawalProviderAccountService;
use App\Services\ResponsibleGaming\ResponsibleGamingEnforcementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creating and cancelling a withdrawal REQUEST.
 */
final class WithdrawalService
{
    /** Prefix for the human-facing withdrawal reference. */
    private const REFERENCE_PREFIX = 'WD';

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly WalletLockService $locks,
        private readonly WalletHoldService $holds,
        private readonly FinancialStateTransitionService $transitions,
    ) {}

    /**
     * Record the intention to withdraw money. Moves nothing.
     *
     * @param  array<string, mixed>  $options  provider, provider_reference, fee, payout_details, metadata
     *
     * @throws WithdrawalException
     * @throws InsufficientBalanceException
     * @throws FinancialException
     */
    public function request(
        Wallet $wallet,
        Money $amount,
        PaymentMethod $method = PaymentMethod::Manual,
        ?string $idempotencyKey = null,
        array $options = [],
    ): Withdrawal {
        $amount->assertPositive('withdrawal amount');
        Money::assertExactArithmeticIsAvailable();
        app(\App\Services\Compliance\RealMoneyGate::class)->assertOpen('withdrawal');

        $normalisedKey = $idempotencyKey === null ? null : $this->idempotency->normaliseKey($idempotencyKey);

        $fee = $this->resolveFee($amount, $options);
        $netAmount = $this->resolveNetAmount($amount, $fee);

        /** @var User|null $rgUser */
        $rgUser = User::query()->find($wallet->user_id);

        if ($rgUser instanceof User) {
            app(ResponsibleGamingEnforcementService::class)
                ->assertWithdrawalAllowed($rgUser);

            app(ComplianceKycGate::class)
                ->assertCanWithdraw($rgUser, $amount->toString(), $amount->currency());
        }

        return $this->withinTransaction(function () use ($wallet, $amount, $fee, $netAmount, $method, $normalisedKey, $options, $rgUser): Withdrawal {
            $lockedWallet = $this->locks->lock((int) $wallet->getKey());

            $this->assertWalletAllowsWithdrawal($lockedWallet, $amount->currency());
            $this->assertAmountWithinLimits($amount);

            if ($normalisedKey !== null) {
                $existing = $this->findByIdempotencyKey($normalisedKey);

                if ($existing instanceof Withdrawal) {
                    $this->assertReplayMatches($existing, $lockedWallet, $amount, $fee, $method);

                    return $existing;
                }
            }

            app(StripeRefundWithdrawalGuard::class)->assertAllowed($lockedWallet);

            $available = $this->holds->availableAmount($lockedWallet);

            if ($available->isLessThan($amount)) {
                throw new InsufficientBalanceException(
                    (int) $lockedWallet->getKey(),
                    $amount->toString(),
                    $available->toString(),
                    $lockedWallet->currency,
                );
            }

            if ($rgUser instanceof User) {
                app(\App\Services\Compliance\MoneyFlowAmlObservationService::class)->observe(
                    $rgUser,
                    \App\Services\Compliance\MoneyFlowAmlObservationService::FLOW_WITHDRAWAL,
                    ['amount' => $amount->toString(), 'currency' => $amount->currency()->value],
                );
            }

            $metadata = $this->buildMetadata($options, $method);

            $withdrawal = new Withdrawal;

            $withdrawal->fill([
                'reference_number' => $this->generateReferenceNumber(),
                'user_id' => (int) $lockedWallet->user_id,
                'wallet_id' => (int) $lockedWallet->getKey(),
                'method' => $method,
                'provider' => $this->stringOption($options, 'provider'),
                'provider_reference' => $this->stringOption($options, 'provider_reference'),
                'idempotency_key' => $normalisedKey,
                'currency' => $amount->currency(),
                'amount' => $amount->toString(),
                'fee' => $fee->toString(),
                'net_amount' => $netAmount->toString(),
                'payout_details' => isset($options['payout_details']) && is_array($options['payout_details'])
                    ? $options['payout_details']
                    : null,
                'metadata' => $metadata,
            ]);

            $withdrawal->uuid = (string) Str::uuid();
            $withdrawal->status = WithdrawalStatus::Pending;
            $withdrawal->financial_transaction_id = null;
            $withdrawal->requested_at = Carbon::now();
            $withdrawal->approved_at = null;
            $withdrawal->rejected_at = null;
            $withdrawal->completed_at = null;

            $metadata[WithdrawalIntegrityGuard::TERMS_KEY] = app(WithdrawalIntegrityGuard::class)->termsFingerprint($withdrawal);
            $withdrawal->metadata = $metadata;

            try {
                $withdrawal->save();
            } catch (QueryException $exception) {
                if ($normalisedKey !== null && $this->idempotency->isDuplicateKeyViolation($exception)) {
                    $winner = $this->findByIdempotencyKey($normalisedKey);

                    if ($winner instanceof Withdrawal) {
                        $this->assertReplayMatches($winner, $lockedWallet, $amount, $fee, $method);

                        return $winner;
                    }
                }

                throw $exception;
            }

            return $withdrawal;
        });
    }

    /**
     * Readable alias of request().
     *
     * @param  array<string, mixed>  $options
     *
     * @throws FinancialException
     */
    public function create(
        Wallet $wallet,
        Money $amount,
        PaymentMethod $method = PaymentMethod::Manual,
        ?string $idempotencyKey = null,
        array $options = [],
    ): Withdrawal {
        return $this->request($wallet, $amount, $method, $idempotencyKey, $options);
    }

    /**
     * Move the request into manual review.
     *
     * @throws WithdrawalException
     * @throws FinancialException
     */
    public function markUnderReview(Withdrawal $withdrawal, ?int $reviewerUserId = null): Withdrawal
    {
        return $this->withinTransaction(function () use ($withdrawal, $reviewerUserId): Withdrawal {
            $this->locks->lock((int) $withdrawal->wallet_id);
            $current = $this->transitions->lockWithdrawal($withdrawal);

            if ($current->status !== WithdrawalStatus::Pending) {
                throw WithdrawalException::notApprovable($current->id, $current->status);
            }

            return $this->transitions->transitionWithdrawal($current, WithdrawalStatus::UnderReview, array_filter([
                'reviewed_by' => $reviewerUserId,
                'reviewed_at' => Carbon::now(),
            ], static fn ($value): bool => $value !== null));
        });
    }

    /**
     * Cancel the request and give back any reservation.
     *
     * @return array{withdrawal: Withdrawal, wallet: Wallet, hold_released: bool}
     *
     * @throws WithdrawalException
     * @throws FinancialException
     */
    public function cancel(Withdrawal $withdrawal, ?string $reason = null, ?int $actorUserId = null): array
    {
        return $this->withinTransaction(function () use ($withdrawal, $reason, $actorUserId): array {
            $lockedWallet = $this->locks->lock((int) $withdrawal->wallet_id);
            $current = $this->transitions->lockWithdrawal($withdrawal);

            $this->assertWalletMatches($current, $lockedWallet);

            if ($current->status === WithdrawalStatus::Completed) {
                throw WithdrawalException::alreadyCompleted($current->id, $current->financial_transaction_id);
            }

            if (! $current->status->canCancel()) {
                throw WithdrawalException::notCancellable($current->id, $current->status);
            }

            $guard = app(WithdrawalIntegrityGuard::class);
            $guard->assertTerms($current, $lockedWallet);

            if ((is_array($current->metadata) ? ($current->metadata[WithdrawalDisbursementService::CLAIM] ?? null) : null) !== null) {
                $guard->refuse();
            }

            $released = false;
            $wallet = $lockedWallet;

            $reservations = app(WithdrawalReservationService::class);
            $initialHolds = app(WithdrawalInitialHoldService::class);

            if ($current->status->holdsReservedFunds() || $reservations->exists($current)) {
                $outcome = $reservations->release($current, $lockedWallet, false);
                $wallet = $outcome['wallet'];
                $released = $outcome['released'];
            } else {
                $initialHolds->assertRequiredExists($current, $lockedWallet);

                if ($initialHolds->exists($current)) {
                    $outcome = $initialHolds->releasePending($current, $lockedWallet);
                    $wallet = $outcome['wallet'];
                    $released = $outcome['released'];
                }
            }

            $updated = $this->transitions->transitionWithdrawal($current, WithdrawalStatus::Cancelled, [
                'rejected_at' => Carbon::now(),
                'rejection_reason' => $reason ?? 'Cancelled by request.',
                'metadata' => $this->mergeMetadata($current, array_filter([
                    'cancelled_at' => Carbon::now()->toIso8601String(),
                    'cancelled_by' => $actorUserId,
                    'hold_released' => $released,
                ], static fn ($value): bool => $value !== null)),
            ]);

            $this->holds->assertInvariant($wallet);

            return [
                'withdrawal' => $updated,
                'wallet' => $wallet,
                'hold_released' => $released,
            ];
        });
    }

    /**
     * The amount of a request as an exact Money value.
     */
    public function amountOf(Withdrawal $withdrawal): Money
    {
        return Money::fromDatabase((string) $withdrawal->amount, $withdrawal->currency);
    }

    /**
     * The amount that should actually reach the beneficiary.
     */
    public function netAmountOf(Withdrawal $withdrawal): Money
    {
        return Money::fromDatabase((string) $withdrawal->net_amount, $withdrawal->currency);
    }

    /**
     * The hold type a withdrawal reservation uses.
     */
    public function holdType(): WalletHoldType
    {
        return WalletHoldType::Withdrawal;
    }

    public function referenceType(): FinancialReferenceType
    {
        return FinancialReferenceType::Withdrawal;
    }

    /**
     * Soft-deleted rows included: the unique index covers them.
     */
    public function findByIdempotencyKey(string $key): ?Withdrawal
    {
        return Withdrawal::withTrashed()
            ->where('idempotency_key', $this->idempotency->normaliseKey($key))
            ->first();
    }

    public function findByReferenceNumber(string $referenceNumber): ?Withdrawal
    {
        return Withdrawal::query()->byReferenceNumber($referenceNumber)->first();
    }

    public function deterministicKeyFor(Wallet $wallet, Money $amount, PaymentMethod $method, string $discriminator): string
    {
        return $this->idempotency->deterministicKey('withdrawal-request', implode('|', [
            (string) $wallet->getKey(),
            $amount->toString(),
            $amount->currency()->value,
            $method->value,
            $discriminator,
        ]));
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws FinancialException
     */
    public function resolveFee(Money $amount, array $options = []): Money
    {
        if (isset($options['fee'])) {
            $fee = $options['fee'] instanceof Money
                ? $options['fee']
                : Money::of((string) $options['fee'], $amount->currency());

            $fee->assertNotNegative('withdrawal fee');
            $amount->assertSameCurrency($fee);

            return $fee;
        }

        $percentage = (string) config('finance.withdrawal.fee_percentage', '0.00');

        if (bccomp($percentage, '0', 6) === 0) {
            return Money::zero($amount->currency());
        }

        $raw = bcdiv(bcmul($amount->amount(), $percentage, 6), '100', 6);

        return Money::of($this->roundHalfUp($raw, $amount->scale()), $amount->currency());
    }

    /**
     * The wallet is debited by `amount`; the beneficiary receives `net_amount`.
     * The fee stays with the operator, so net must be positive.
     *
     * @throws WithdrawalException
     */
    public function resolveNetAmount(Money $amount, Money $fee): Money
    {
        $amount->assertSameCurrency($fee);

        if ($fee->isGreaterThan($amount)) {
            throw WithdrawalException::netAmountInvalid($amount->toString(), $fee->toString(), 'negative');
        }

        $net = $amount->minus($fee);

        if (! $net->isPositive()) {
            throw WithdrawalException::netAmountInvalid($amount->toString(), $fee->toString(), $net->toString());
        }

        return $net;
    }

    /**
     * @throws WithdrawalException
     */
    public function assertAmountWithinLimits(Money $amount): void
    {
        $min = Money::of((string) config('finance.withdrawal.min', '0.00'), $amount->currency());
        $max = Money::of((string) config('finance.withdrawal.max', '0.00'), $amount->currency());

        if ($amount->isLessThan($min) || $amount->isGreaterThan($max)) {
            throw WithdrawalException::amountOutOfRange(
                $amount->toString(),
                $min->toString(),
                $max->toString(),
                $amount->currency(),
            );
        }
    }

    /**
     * Currency must match exactly and the wallet must be debitable.
     *
     * @throws WithdrawalException
     */
    public function assertWalletAllowsWithdrawal(Wallet $wallet, Currency $currency): void
    {
        if ($wallet->currency !== $currency) {
            throw WithdrawalException::currencyMismatch((int) $wallet->getKey(), $wallet->currency, $currency);
        }

        if (! $wallet->canDebit()) {
            throw WithdrawalException::walletNotDebitable((int) $wallet->getKey(), $wallet->status->value);
        }
    }

    /**
     * @throws FinancialException
     */
    public function generateReferenceNumber(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = substr(sprintf(
                '%s-%s-%s',
                self::REFERENCE_PREFIX,
                Carbon::now()->format('Ymd'),
                strtoupper(bin2hex(random_bytes(6))),
            ), 0, 64);

            if (! Withdrawal::withTrashed()->where('reference_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw FinancialException::withCode(
            'withdrawal_reference_generation_failed',
            'Could not generate a unique withdrawal reference number after several attempts.',
        );
    }

    /**
     * @throws WithdrawalException
     */
    private function assertReplayMatches(Withdrawal $existing, Wallet $wallet, Money $amount, Money $fee, PaymentMethod $method): void
    {
        $mismatches = [];

        if ((int) $existing->wallet_id !== (int) $wallet->getKey()) {
            $mismatches['wallet_id'] = (int) $existing->wallet_id;
        }

        if ($existing->currency !== $amount->currency()) {
            $mismatches['currency'] = $existing->currency->value;
        }

        if (bccomp((string) $existing->amount, $amount->amount(), $amount->scale()) !== 0) {
            $mismatches['amount'] = (string) $existing->amount;
        }

        if (bccomp((string) $existing->fee, $fee->amount(), $fee->scale()) !== 0) {
            $mismatches['fee'] = (string) $existing->fee;
        }

        if ($existing->method !== $method) {
            $mismatches['method'] = $existing->method->value;
        }

        if ($mismatches === []) {
            return;
        }

        throw WithdrawalException::withReason(
            sprintf(
                'Idempotency key already belongs to withdrawal %d, which describes a different operation.',
                (int) $existing->getKey(),
            ),
            'withdrawal_idempotency_conflict',
            array_merge(
                ['withdrawal_id' => (int) $existing->getKey()],
                array_combine(
                    array_map(static fn (string $field): string => 'existing_'.$field, array_keys($mismatches)),
                    array_values($mismatches),
                ) ?: [],
            ),
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
     * Never contains payout details: the beneficiary payload stays in the
     * encrypted column only.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildMetadata(array $options, PaymentMethod $method): array
    {
        $metadata = isset($options['metadata']) && is_array($options['metadata'])
            ? $options['metadata']
            : [];

        unset(
            $metadata['payout_details'],
            $metadata[WithdrawalDisbursementService::CLAIM],
            $metadata[WithdrawalProviderAccountService::METADATA_KEY],
            $metadata[WithdrawalIntegrityGuard::TERMS_KEY],
        );

        $metadata[WithdrawalProviderAccountService::METADATA_KEY] = app(WithdrawalProviderAccountService::class)
            ->current($method->value);
        $metadata['requested_at'] = Carbon::now()->toIso8601String();

        return $metadata;
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

    private function roundHalfUp(string $value, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;

        $increment = '0.'.str_repeat('0', $scale).'5';
        $rounded = bcadd($absolute, $increment, $scale);

        return $negative ? '-'.$rounded : $rounded;
    }

    private function withinTransaction(\Closure $callback): mixed
    {
        return DB::transaction($callback);
    }
}
