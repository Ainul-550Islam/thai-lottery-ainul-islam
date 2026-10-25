<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\PaymentCallbackData;
use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PromptPayDepositMatchResult;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\WebhookReceipt;
use App\Services\Finance\WalletLockService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PromptPayDepositMatcher
{
    public function __construct(
        private readonly WalletLockService $walletLocks,
        private readonly DepositCallbackApplicationService $callbacks,
    ) {}

    public function match(
        string $bank,
        string $reference,
        string $amount,
        string $currency,
        string $bankTransactionId,
    ): PromptPayDepositMatchResult {
        $reference = trim($reference);
        $currency = trim($currency);
        $bankTransactionId = trim($bankTransactionId);
        $normalizedAmount = $this->normalizeAmount($amount);

        return DB::transaction(function () use ($bank, $reference, $amount, $normalizedAmount, $currency, $bankTransactionId): PromptPayDepositMatchResult {
            if ($reference === '' || $bankTransactionId === '') {
                $this->audit(null, $bank, $reference, $amount, $currency, $bankTransactionId, 'unmatched', 'missing_reference_or_bank_transaction');

                return PromptPayDepositMatchResult::Unmatched;
            }

            $firstDelivery = WebhookReceipt::query()->insertOrIgnore([
                'gateway' => $bank,
                'nonce' => hash('sha256', $bank.':'.$bankTransactionId),
                'signature_timestamp' => time(),
                'status' => WebhookReceipt::STATUS_ACCEPTED,
                'ip_address' => null,
                'path' => 'promptpay-deposit-matcher',
                'received_at' => now(),
            ]) === 1;

            $candidateIds = $this->candidateQuery($reference)->orderBy('id')->get(['id', 'wallet_id']);
            if ($candidateIds->isEmpty()) {
                $this->audit(null, $bank, $reference, $amount, $currency, $bankTransactionId, 'unmatched', 'unknown_reference');

                return PromptPayDepositMatchResult::Unmatched;
            }

            $this->walletLocks->lockMany($candidateIds->pluck('wallet_id')->all());
            $deposits = $this->candidateQuery($reference)->orderBy('id')->lockForUpdate()->get();
            if ($deposits->count() !== 1 || $deposits->pluck('id')->all() !== $candidateIds->pluck('id')->all()) {
                foreach ($deposits as $deposit) {
                    $this->review($deposit, $bank, $amount, $currency, $bankTransactionId, 'ambiguous_reference');
                }

                return PromptPayDepositMatchResult::NeedsReview;
            }

            /** @var Deposit $deposit */
            $deposit = $deposits->sole();
            if ($deposit->status === DepositStatus::Confirmed) {
                $sameCredit = $this->sameBankCredit($deposit, $bank, $bankTransactionId)
                    && $normalizedAmount !== null
                    && bccomp($normalizedAmount, (string) $deposit->amount, 2) === 0
                    && $currency === Currency::THB->value
                    && $deposit->currency === Currency::THB;

                if ($firstDelivery && $sameCredit) {
                    $this->review($deposit, $bank, $amount, $currency, $bankTransactionId, 'already_credited_with_new_bank_transaction');

                    return PromptPayDepositMatchResult::NeedsReview;
                }
                if ($sameCredit) {
                    return PromptPayDepositMatchResult::AlreadyCredited;
                }

                $this->review($deposit, $bank, $amount, $currency, $bankTransactionId, 'confirmed_credit_conflict');

                return PromptPayDepositMatchResult::NeedsReview;
            }

            if (! $firstDelivery) {
                $this->review($deposit, $bank, $amount, $currency, $bankTransactionId, 'duplicate_bank_transaction_requires_review');

                return PromptPayDepositMatchResult::NeedsReview;
            }
            if ($normalizedAmount === null) {
                return $this->needsReview($deposit, $bank, $amount, $currency, $bankTransactionId, 'malformed_amount');
            }
            if ($currency !== Currency::THB->value || $deposit->currency !== Currency::THB) {
                return $this->needsReview($deposit, $bank, $amount, $currency, $bankTransactionId, 'currency_mismatch');
            }
            if (bccomp($normalizedAmount, (string) $deposit->amount, 2) !== 0) {
                return $this->needsReview($deposit, $bank, $amount, $currency, $bankTransactionId, 'amount_mismatch');
            }
            if ($deposit->status !== DepositStatus::Pending) {
                return $this->needsReview($deposit, $bank, $amount, $currency, $bankTransactionId, 'deposit_not_payable');
            }

            $payments = Payment::withTrashed()
                ->where('payable_type', Deposit::class)
                ->where('payable_id', $deposit->id)
                ->lockForUpdate()
                ->get();
            if ($payments->count() !== 1) {
                return $this->needsReview($deposit, $bank, $amount, $currency, $bankTransactionId, 'original_payment_not_unique');
            }

            /** @var Payment $payment */
            $payment = $payments->sole();
            if ($payment->trashed()
                || $payment->method !== PaymentMethod::PromptPay
                || $payment->gateway !== 'promptpay'
                || $payment->currency !== Currency::THB
                || $payment->gateway_reference !== $reference
                || bccomp((string) $payment->amount, (string) $deposit->amount, 2) !== 0) {
                return $this->needsReview($deposit, $bank, $amount, $currency, $bankTransactionId, 'original_payment_mismatch');
            }
            if ($this->expired($deposit, $payment)) {
                return $this->needsReview($deposit, $bank, $amount, $currency, $bankTransactionId, 'expired_or_missing_expiry');
            }

            try {
                $callback = PaymentCallbackData::fromInput(
                    providerCode: 'promptpay',
                    externalReference: $reference,
                    amount: $normalizedAmount,
                    currency: Currency::THB->value,
                    providerStatus: PaymentTransactionStatus::Succeeded,
                    failureReason: null,
                    signature: null,
                    payloadFingerprint: hash('sha256', implode('|', [$bank, $reference, $normalizedAmount, $currency, $bankTransactionId])),
                    providerEventId: $bank.':'.$bankTransactionId,
                );
                $applied = $this->callbacks->apply($callback, $payment);
            } catch (FinancialException) {
                return $this->needsReview($deposit, $bank, $amount, $currency, $bankTransactionId, 'original_evidence_requires_review');
            }

            $currentPayment = $applied['payment'];
            $metadata = is_array($currentPayment->metadata) ? $currentPayment->metadata : [];
            $metadata['promptpay_bank'] = $bank;
            $metadata['promptpay_bank_transaction_id'] = $bankTransactionId;
            $currentPayment->metadata = $metadata;
            $currentPayment->save();

            $currentDeposit = Deposit::query()->findOrFail($deposit->id);
            $credited = (bool) ($applied['replayed'] ?? false) === false && $currentDeposit->status === DepositStatus::Confirmed;
            $this->audit($currentDeposit, $bank, $reference, $normalizedAmount, $currency, $bankTransactionId, $credited ? 'credited' : 'already_credited', null);

            return $credited ? PromptPayDepositMatchResult::Credited : PromptPayDepositMatchResult::AlreadyCredited;
        }, 3);
    }

    private function candidateQuery(string $reference)
    {
        return Deposit::query()
            ->where('provider_reference', $reference)
            ->where('method', PaymentMethod::PromptPay->value);
    }

    private function normalizeAmount(string $amount): ?string
    {
        $amount = trim($amount);
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $amount) !== 1 || bccomp($amount, '0', 2) <= 0) {
            return null;
        }

        return bcadd($amount, '0', 2);
    }

    private function expired(Deposit $deposit, Payment $payment): bool
    {
        $value = data_get($deposit->metadata, 'promptpay_expires_at')
            ?? data_get($payment->metadata, 'original_initiation_response.metadata.expires_at');
        if (! is_string($value) || trim($value) === '') {
            return true;
        }

        try {
            return CarbonImmutable::parse($value)->lessThanOrEqualTo(now());
        } catch (Throwable) {
            return true;
        }
    }

    private function sameBankCredit(Deposit $deposit, string $bank, string $bankTransactionId): bool
    {
        $payment = Payment::query()
            ->where('payable_type', Deposit::class)
            ->where('payable_id', $deposit->id)
            ->first();

        return $payment !== null
            && data_get($payment->metadata, 'promptpay_bank') === $bank
            && data_get($payment->metadata, 'promptpay_bank_transaction_id') === $bankTransactionId;
    }

    private function needsReview(
        Deposit $deposit,
        string $bank,
        string $amount,
        string $currency,
        string $bankTransactionId,
        string $reason,
    ): PromptPayDepositMatchResult {
        $this->review($deposit, $bank, $amount, $currency, $bankTransactionId, $reason);

        return PromptPayDepositMatchResult::NeedsReview;
    }

    private function review(
        Deposit $deposit,
        string $bank,
        string $amount,
        string $currency,
        string $bankTransactionId,
        string $reason,
    ): void {
        $metadata = is_array($deposit->metadata) ? $deposit->metadata : [];
        $metadata['needs_review'] = true;
        $metadata['review_state'] = 'needs_review';
        $metadata['promptpay_review'] = [
            'bank' => $bank,
            'bank_transaction_id' => $bankTransactionId,
            'received_amount' => $amount,
            'received_currency' => $currency,
            'reason' => $reason,
            'recorded_at' => now()->toIso8601String(),
        ];
        $deposit->metadata = $metadata;
        $deposit->save();

        $this->audit($deposit, $bank, (string) $deposit->provider_reference, $amount, $currency, $bankTransactionId, 'needs_review', $reason);
    }

    private function audit(
        ?Deposit $deposit,
        string $bank,
        string $reference,
        string $amount,
        string $currency,
        string $bankTransactionId,
        string $outcome,
        ?string $reason,
    ): void {
        AuditLog::query()->create([
            'user_id' => $deposit?->user_id,
            'action' => AuditAction::Deposit,
            'risk_level' => $outcome === 'credited' || $outcome === 'already_credited' ? RiskLevel::Low : RiskLevel::High,
            'auditable_type' => $deposit === null ? null : Deposit::class,
            'auditable_id' => $deposit?->id,
            'description' => 'PromptPay inbound match: '.$outcome.($reason === null ? '' : ' ('.$reason.')'),
            'new_values' => ['review_state' => $outcome === 'needs_review' ? 'needs_review' : null],
            'metadata' => [
                'bank' => $bank,
                'reference' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'bank_transaction_id' => $bankTransactionId,
                'outcome' => $outcome,
                'reason' => $reason,
            ],
        ]);
    }
}
