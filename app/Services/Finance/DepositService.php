<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\FinancialReferenceType;
use App\Enums\PaymentMethod;
use App\Enums\TruemoneyRedemptionStatus;
use App\Exceptions\DepositException;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\TruemoneyVoucherRedemption;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Compliance\RealMoneyGate;
use App\Services\Payment\DepositProviderAccountService;
use App\Services\ResponsibleGaming\ResponsibleGamingEnforcementService;
use App\Services\ResponsibleGaming\SelfExclusionReleaseGate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creating and cancelling a deposit REQUEST.
 *
 * THE ONE RULE THAT MATTERS HERE
 * ------------------------------
 * Creating a deposit request MUST NOT credit the wallet. This class contains no
 * call to WalletService::credit(), no call to FinancialTransactionService and no
 * balance arithmetic at all. What it produces is an intent row in `deposits` with
 * status `pending` and `financial_transaction_id` still null. Money appears only
 * in DepositCompletionService, after an approval.
 *
 * NO PAYMENT GATEWAY. This phase integrates nothing: no Stripe, no crypto, no
 * bKash, no Nagad, no provider SDK, no webhook and no credential. `provider` and
 * `provider_reference` are accepted as plain non-secret strings so a later phase
 * can reconcile against a provider statement, and nothing here calls out to a
 * network.
 *
 * IDEMPOTENCY
 * `deposits.idempotency_key` is UNIQUE in the audited schema. A retried request
 * carrying the same key returns the row that already exists instead of creating a
 * second one, and a key that exists with different terms is refused as a conflict
 * rather than silently reused. Amounts are compared as exact decimal strings.
 *
 * LOCK ORDER
 * WALLET -> FINANCIAL ENTITY -> LEDGER ACCOUNTS, the same order as everywhere
 * else in the engine. Creation takes the wallet lock only to read a consistent
 * currency and status; the deposit row does not exist yet, so there is nothing
 * after it to lock.
 *
 * EXACT ARITHMETIC ONLY. Every amount is a Money value object over bcmath. No
 * float is constructed in this file.
 */
final class DepositService
{
    /** Prefix for the human-facing deposit reference. */
    private const REFERENCE_PREFIX = 'DP';

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly WalletLockService $locks,
        private readonly FinancialStateTransitionService $transitions,
    ) {}

    /**
     * Record the intention to deposit money. Credits nothing.
     *
     * @param  array<string, mixed>  $options  method, provider, provider_reference, fee, metadata
     * @return Deposit the pending request
     *
     * @throws DepositException
     * @throws FinancialException
     */
    public function request(
        Wallet $wallet,
        Money $amount,
        PaymentMethod $method = PaymentMethod::Manual,
        ?string $idempotencyKey = null,
        array $options = [],
    ): Deposit {
        return $this->requestDeposit($wallet, $amount, $method, $idempotencyKey, $options, null);
    }

    /**
     * Settle a voucher already consumed by the provider, without admitting a
     * new provider call. Only a locked, persisted, successful and risk-cleared
     * receipt belonging to this wallet and exact amount can cross a closed
     * launch switch. Caller metadata is not evidence and cannot select the
     * provider, reference, method or idempotency key.
     *
     * @param array<string, mixed> $receiptMetadata Non-authoritative annotation only.
     */
    public function requestFromConfirmedTrueMoneyVoucher(
        Wallet $wallet,
        Money $amount,
        TruemoneyVoucherRedemption $redemption,
        array $receiptMetadata = [],
    ): Deposit {
        if (DB::transactionLevel() < 1 || $redemption->getKey() === null) {
            throw FinancialException::withCode('truemoney_receipt_unproven', 'A locked provider receipt is required for voucher reconciliation.');
        }

        // The two existing callers already lock this same row before reaching
        // the wallet. Re-read it under lock so stale caller objects are no proof.
        $receipt = TruemoneyVoucherRedemption::query()->lockForUpdate()->find($redemption->getKey());
        $payload = $receipt instanceof TruemoneyVoucherRedemption && is_array($receipt->provider_payload)
            ? $receipt->provider_payload : [];
        $hash = $receipt instanceof TruemoneyVoucherRedemption ? (string) $receipt->voucher_hash : '';
        $status = $receipt instanceof TruemoneyVoucherRedemption ? strtoupper(trim((string) $receipt->provider_status)) : '';
        $payloadStatus = strtoupper(trim((string) ($payload['provider_status'] ?? '')));
        $rawAmount = $payload['amount'] ?? null;
        $validAmount = (is_string($rawAmount) || is_int($rawAmount))
            && preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/D', (string) $rawAmount) === 1;
        if (! $receipt instanceof TruemoneyVoucherRedemption
            || $receipt->status !== TruemoneyRedemptionStatus::RedeemedUnconfirmed
            || $receipt->deposit_id !== null
            || (int) $receipt->user_id !== (int) $wallet->user_id
            || $wallet->currency !== Currency::THB || $amount->currency() !== Currency::THB
            || strtoupper((string) $receipt->currency) !== Currency::THB->value
            || strtoupper((string) ($payload['currency'] ?? '')) !== Currency::THB->value
            || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1
            || ! in_array($status, ['SUCCESS', 'SUCCEEDED', '0000', '200'], true)
            || ($payloadStatus !== '' && $payloadStatus !== $status)
            || ! is_int($payload['http_status'] ?? null) || $payload['http_status'] < 200 || $payload['http_status'] >= 300
            || strtolower((string) ($payload['risk_decision'] ?? '')) !== 'allow'
            || ! $validAmount || bccomp((string) $rawAmount, $amount->toString(), 2) !== 0
            || ($receipt->amount !== null && bccomp((string) $receipt->amount, $amount->toString(), 2) !== 0)) {
            throw FinancialException::withCode('truemoney_receipt_unproven', 'Stored voucher evidence does not prove this deposit.');
        }

        $railMetadata = [
            'provider_kind' => 'truemoney_voucher',
            'voucher_hash' => $hash,
            'voucher_last4' => (string) $receipt->voucher_last4,
        ] + $receiptMetadata;

        return $this->requestDeposit($wallet, $amount, PaymentMethod::BankTransfer, 'truemoney-'.$hash, [
            'provider' => PaymentMethod::BankTransfer->value,
            'provider_reference' => 'TMN-'.substr($hash, 0, 16),
            'metadata' => ['thai_rails' => $railMetadata],
        ], (int) $receipt->user_id);
    }

    /** @param array<string, mixed> $options */
    private function requestDeposit(
        Wallet $wallet,
        Money $amount,
        PaymentMethod $method,
        ?string $idempotencyKey,
        array $options,
        ?int $confirmedVoucherUserId,
    ): Deposit {
        $amount->assertPositive('deposit amount');
        Money::assertExactArithmeticIsAvailable();

        $normalisedKey = $idempotencyKey === null ? null : $this->idempotency->normaliseKey($idempotencyKey);

        $fee = $this->resolveFee($amount, $options);
        $netAmount = $this->resolveNetAmount($amount, $fee);
        $scope = app(DepositProviderAccountService::class)->current($method->value);

        return $this->withinTransaction(function () use ($wallet, $amount, $fee, $netAmount, $method, $normalisedKey, $scope, $options, $confirmedVoucherUserId): Deposit {
            $lockedWallet = $this->locks->lock((int) $wallet->getKey());

            if ($confirmedVoucherUserId !== null && ((int) $lockedWallet->user_id !== $confirmedVoucherUserId
                || $lockedWallet->currency !== Currency::THB)) {
                throw FinancialException::withCode('truemoney_receipt_unproven', 'The current wallet no longer matches the voucher receipt.');
            }
            $this->assertWalletAcceptsDeposit($lockedWallet, $amount->currency());
            $this->assertAmountWithinLimits($amount);
            app(DepositAdmission::class)->lockPlayer($lockedWallet);

            if ($normalisedKey !== null) {
                $existing = $this->findByIdempotencyKey($normalisedKey, true);

                if ($existing instanceof Deposit) {
                    app(DepositIntegrityGuard::class)->assertTerms($existing, $lockedWallet);
                    if (app(DepositProviderAccountService::class)->stored($existing) !== $scope) {
                        app(DepositIntegrityGuard::class)->refuse();
                    }
                    $this->assertReplayMatches($existing, $lockedWallet, $amount, $fee, $method);

                    return $existing;
                }
            }

            // A proven replay is a read of the original obligation, not a new
            // admission. The sole exception is settlement of an already consumed,
            // locked and independently rechecked TrueMoney receipt above; it
            // cannot dispatch the provider or be authorized by caller metadata.
            if ($confirmedVoucherUserId === null) {
                app(RealMoneyGate::class)->assertOpen('deposit');
            }
            app(DepositAdmission::class)->assertAllowed($lockedWallet, $amount->toString());
            app(SelfExclusionReleaseGate::class)->assertMayPlay((int) $lockedWallet->user_id);

            // Provider-account identity is owned by the server, not by
            // customer-supplied metadata.
            $options['metadata'] = isset($options['metadata']) && is_array($options['metadata'])
                ? $options['metadata'] : [];
            $options['metadata'][DepositProviderAccountService::KEY] = $scope;

            $deposit = new Deposit;

            $deposit->fill([
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
                'metadata' => $this->buildMetadata($options),
            ]);

            // System-assigned, therefore never mass assignable.
            $deposit->uuid = (string) Str::uuid();

            // Explicitly pending: a new request has not been reviewed and has
            // certainly not been credited.
            $deposit->status = DepositStatus::Pending;
            $deposit->financial_transaction_id = null;
            $deposit->confirmed_at = null;
            $deposit->failed_at = null;

            $termsMeta = is_array($deposit->metadata) ? $deposit->metadata : [];
            $termsMeta[DepositIntegrityGuard::TERMS_KEY] = app(DepositIntegrityGuard::class)->termsFingerprint($deposit);
            $deposit->metadata = $termsMeta;

            try {
                $deposit->save();
            } catch (QueryException $exception) {
                // Lost a race on the unique idempotency key: return the winner.
                if ($normalisedKey !== null && $this->idempotency->isDuplicateKeyViolation($exception)) {
                    $winner = $this->findByIdempotencyKey($normalisedKey, true);

                    if ($winner instanceof Deposit) {
                        app(DepositIntegrityGuard::class)->assertTerms($winner, $lockedWallet);
                        if (app(DepositProviderAccountService::class)->stored($winner) !== $scope) {
                            app(DepositIntegrityGuard::class)->refuse();
                        }
                        $this->assertReplayMatches($winner, $lockedWallet, $amount, $fee, $method);

                        return $winner;
                    }
                }

                throw $exception;
            }

            return $deposit;
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
    ): Deposit {
        return $this->request($wallet, $amount, $method, $idempotencyKey, $options);
    }

    /**
     * Withdraw the request before it was ever credited.
     *
     * Only `pending` and `approved` qualify, and neither of those has moved money,
     * so no balance change and no ledger entry is involved. A confirmed deposit is
     * NOT cancellable: reversing money that already arrived requires a new
     * compensating transaction, which is FinancialReversalService's job.
     *
     * @throws DepositException
     * @throws FinancialException
     */
    public function cancel(Deposit $deposit, ?string $reason = null, ?int $actorUserId = null): Deposit
    {
        return $this->withinTransaction(function () use ($deposit, $reason, $actorUserId): Deposit {
            $lockedWallet = $this->locks->lock((int) $deposit->wallet_id);
            $current = $this->transitions->lockDeposit($deposit);

            $this->assertWalletMatches($current, $lockedWallet);

            if ($current->isCredited() || $current->status === DepositStatus::Confirmed) {
                throw DepositException::alreadyCredited($current->id, $current->financial_transaction_id);
            }

            if (! $current->status->canCancel()) {
                throw DepositException::notRejectable($current->id, $current->status);
            }

            return $this->transitions->transitionDeposit($current, DepositStatus::Cancelled, [
                'failed_at' => Carbon::now(),
                'failure_reason' => $reason ?? 'Cancelled before completion.',
                'metadata' => $this->mergeMetadata($current, array_filter([
                    'cancelled_at' => Carbon::now()->toIso8601String(),
                    'cancelled_by' => $actorUserId,
                    'cancellation_reason' => $reason,
                ], static fn ($value): bool => $value !== null)),
            ]);
        });
    }

    /**
     * Look up a request by its idempotency key.
     *
     * Soft-deleted rows are included because the unique index covers them: a key
     * belonging to a trashed row is still taken by the database.
     */
    public function findByIdempotencyKey(string $key, bool $lock = false): ?Deposit
    {
        $query = Deposit::withTrashed()
            ->where('idempotency_key', $this->idempotency->normaliseKey($key));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function findByReferenceNumber(string $referenceNumber): ?Deposit
    {
        return Deposit::query()->byReferenceNumber($referenceNumber)->first();
    }

    /**
     * A stable idempotency key for a request, derived from its own terms.
     *
     * Offered so a caller with no key of its own still gets replay protection
     * instead of no protection at all.
     */
    public function deterministicKeyFor(Wallet $wallet, Money $amount, PaymentMethod $method, string $discriminator): string
    {
        return $this->idempotency->deterministicKey('deposit-request', implode('|', [
            (string) $wallet->getKey(),
            $amount->toString(),
            $amount->currency()->value,
            $method->value,
            $discriminator,
        ]));
    }

    /**
     * The fee that applies, as an exact amount.
     *
     * An explicit fee in $options wins; otherwise the configured percentage is
     * applied with bcmath. The result is stored, never recomputed later, so the
     * fee that applied at request time stays auditable.
     *
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

            $fee->assertNotNegative('deposit fee');
            $amount->assertSameCurrency($fee);

            return $fee;
        }

        $percentage = (string) config('finance.deposit.fee_percentage', '0.00');

        if (bccomp($percentage, '0', 6) === 0) {
            return Money::zero($amount->currency());
        }

        $raw = bcdiv(bcmul($amount->amount(), $percentage, 6), '100', 6);

        return Money::of($this->roundHalfUp($raw, $amount->scale()), $amount->currency());
    }

    /**
     * amount - fee, refused if it is not a usable positive amount.
     *
     * @throws DepositException
     */
    public function resolveNetAmount(Money $amount, Money $fee): Money
    {
        $amount->assertSameCurrency($fee);

        if ($fee->isGreaterThan($amount)) {
            throw DepositException::netAmountInvalid($amount->toString(), $fee->toString(), 'negative');
        }

        $net = $amount->minus($fee);

        if (! $net->isPositive()) {
            throw DepositException::netAmountInvalid($amount->toString(), $fee->toString(), $net->toString());
        }

        return $net;
    }

    /**
     * Guard the configured minimum and maximum.
     *
     * @throws DepositException
     */
    public function assertAmountWithinLimits(Money $amount): void
    {
        $minimum = (string) config('finance.deposit.min', '0.00');
        $maximum = (string) config('finance.deposit.max', '0.00');

        $min = Money::of($minimum, $amount->currency());
        $max = Money::of($maximum, $amount->currency());

        if ($amount->isLessThan($min) || $amount->isGreaterThan($max)) {
            throw DepositException::amountOutOfRange(
                $amount->toString(),
                $min->toString(),
                $max->toString(),
                $amount->currency(),
            );
        }
    }

    /**
     * The wallet must exist, be able to receive money, and share the currency.
     *
     * A currency difference is a hard refusal. Converting silently would require
     * inventing an exchange rate, and `finance.currency.conversion_enabled` is
     * false in the audited configuration.
     *
     * @throws DepositException
     */
    public function assertWalletAcceptsDeposit(Wallet $wallet, Currency $currency): void
    {
        if ($wallet->currency !== $currency) {
            throw DepositException::currencyMismatch((int) $wallet->getKey(), $wallet->currency, $currency);
        }

        if (! $wallet->canCredit()) {
            throw DepositException::walletNotCreditable((int) $wallet->getKey(), $wallet->status->value);
        }
    }

    /**
     * The polymorphic reference type used for a deposit's ledger entries.
     */
    public function referenceType(): FinancialReferenceType
    {
        return FinancialReferenceType::Deposit;
    }

    /**
     * A unique, human-quotable reference number.
     *
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

            if (! Deposit::withTrashed()->where('reference_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw FinancialException::withCode(
            'deposit_reference_generation_failed',
            'Could not generate a unique deposit reference number after several attempts.',
        );
    }

    /**
     * A replay must describe the same operation, or it is a conflict.
     *
     * @throws DepositException
     */
    private function assertReplayMatches(Deposit $existing, Wallet $wallet, Money $amount, Money $fee, PaymentMethod $method): void
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

        if (bccomp((string) $existing->fee, $fee->amount(), $amount->scale()) !== 0) {
            $mismatches['fee'] = (string) $existing->fee;
        }

        if ($existing->method !== $method) {
            $mismatches['method'] = $existing->method->value;
        }

        if ($mismatches === []) {
            return;
        }

        throw DepositException::withReason(
            sprintf(
                'Idempotency key already belongs to deposit %d, which describes a different operation.',
                (int) $existing->getKey(),
            ),
            'deposit_idempotency_conflict',
            array_merge(
                ['deposit_id' => (int) $existing->getKey()],
                array_combine(
                    array_map(static fn (string $field): string => 'existing_'.$field, array_keys($mismatches)),
                    array_values($mismatches),
                ) ?: [],
            ),
        );
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
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    private function buildMetadata(array $options): ?array
    {
        $metadata = isset($options['metadata']) && is_array($options['metadata'])
            ? $options['metadata']
            : [];

        $metadata['requested_at'] = Carbon::now()->toIso8601String();

        return $metadata;
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

    /**
     * @param  array<string, mixed>  $options
     */
    private function stringOption(array $options, string $key): ?string
    {
        return isset($options[$key]) && is_string($options[$key]) && $options[$key] !== ''
            ? $options[$key]
            : null;
    }

    /**
     * Half-up rounding on a decimal string. bcmath truncates, so the carry is
     * added explicitly rather than relying on a float.
     */
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
        if (DB::transactionLevel() > 0) {
            return $callback();
        }

        return DB::transaction($callback);
    }
}
