<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\PaymentCallbackData;
use App\DTOs\Payment\PaymentWebhookData;
use App\Enums\AuditAction;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Exceptions\PaymentReconciliationException;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Withdrawal;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositIntegrityGuard;
use App\Services\Finance\WithdrawalCompletionService;

/**
 * Payment callback normalization + application.
 */
final class PaymentCallbackService
{
    public function __construct(
        private readonly DepositCompletionService $depositCompletion,
        private readonly WithdrawalCompletionService $withdrawalCompletion,
    ) {}

    /**
     * READ-ONLY status projection for browser-return pages (FINAL AUDIT #2).
     *
     * @return array{
     *     found: bool,
     *     reason?: string,
     *     payment?: Payment,
     *     document_reference?: string,
     *     state?: string,
     *     paid?: bool
     * }
     */
    public function browserReturnProjection(
        ?string $paymentReference,
        ?string $documentReference,
        ?string $gatewayReference,
        ?int $viewerId,
    ): array {
        if ($viewerId === null) {
            return ['found' => false, 'reason' => 'reference_not_found'];
        }

        $payment = null;
        $paymentReference = self::safeReference($paymentReference);
        $documentReference = self::safeReference($documentReference);
        $gatewayReference = self::safeReference($gatewayReference);

        if ($paymentReference !== null && preg_match('/^PAY-[A-Za-z0-9_-]{1,100}$/i', $paymentReference) === 1) {
            $payment = Payment::query()->where('reference_number', $paymentReference)->first();
        } elseif ($documentReference !== null && preg_match('/^(?:DP|WD)-[A-Za-z0-9_-]{1,100}$/i', $documentReference) === 1) {
            $deposit = Deposit::query()->where('reference_number', $documentReference)->first();

            if ($deposit instanceof Deposit) {
                $payment = Payment::query()
                    ->where('payable_type', Deposit::class)
                    ->where('payable_id', $deposit->getKey())
                    ->first();
            } else {
                $withdrawal = Withdrawal::query()->where('reference_number', $documentReference)->first();

                if ($withdrawal instanceof Withdrawal) {
                    $payment = Payment::query()
                        ->where('payable_type', Withdrawal::class)
                        ->where('payable_id', $withdrawal->getKey())
                        ->first();
                }
            }
        } elseif ($gatewayReference !== null && preg_match('/^[A-Za-z0-9._:-]{1,120}$/', $gatewayReference) === 1) {
            $payment = Payment::query()->where('gateway_reference', $gatewayReference)->first();
        }

        if (! $payment instanceof Payment || (int) $payment->user_id !== $viewerId) {
            return ['found' => false, 'reason' => 'reference_not_found'];
        }

        $paid = $payment->status === PaymentStatus::Captured;

        if ($paid && $payment->payable_type === Deposit::class) {
            $deposit = Deposit::withTrashed()->find($payment->payable_id);
            if (! $deposit instanceof Deposit) {
                $paid = false;
            } elseif (array_key_exists(DepositIntegrityGuard::TERMS_KEY, $deposit->metadata ?? [])) {
                try {
                    $this->depositCompletion->verifySettled($deposit);
                } catch (FinancialException) {
                    $paid = false;
                }
            }
        } elseif ($paid && $payment->payable_type === Withdrawal::class) {
            $withdrawal = Withdrawal::withTrashed()->find($payment->payable_id);
            if (! $withdrawal instanceof Withdrawal || ! $this->withdrawalCompletion->isSettled($withdrawal)) {
                $paid = false;
            }
        }

        $state = match ($payment->status) {
            PaymentStatus::Captured => $paid ? 'confirmed' : 'pending',
            PaymentStatus::Failed => 'failed',
            PaymentStatus::Cancelled => 'cancelled',
            PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded, PaymentStatus::Disputed => 'updated',
            PaymentStatus::Pending, PaymentStatus::Authorized => 'pending',
        };

        return [
            'found' => true,
            'payment' => $payment,
            'document_reference' => (is_array($payment->metadata) ? ($payment->metadata['deposit_reference'] ?? $payment->metadata['withdrawal_reference'] ?? null) : null)
                ?? $payment->reference_number,
            'state' => $state,
            'paid' => $paid,
        ];
    }

    private static function safeReference(?string $reference): ?string
    {
        if (! is_string($reference)) {
            return null;
        }

        $reference = trim($reference);

        return $reference !== '' && strlen($reference) <= 120 ? $reference : null;
    }

    /**
     * Normalize a verified provider payload into a callback fact-set.
     *
     * @throws PaymentReconciliationException
     */
    public function normalizeFromPayload(string $providerCode, array $payload): PaymentCallbackData
    {
        $facts = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : $payload;

        $reference = $facts['reference'] ?? $facts['gateway_reference'] ?? $facts['payment_reference'] ?? $facts['id'] ?? null;
        $amount = $facts['amount'] ?? null;
        $currency = $facts['currency'] ?? null;
        $statusWord = $facts['status'] ?? $payload['status'] ?? null;

        if (! is_string($reference) || trim($reference) === '') {
            throw PaymentReconciliationException::malformed('the payload carries no external reference');
        }

        $normalizedAmount = is_string($amount)
            ? trim($amount)
            : (is_int($amount) ? (string) $amount : null);

        if ($normalizedAmount === null || preg_match('/^\d+(\.\d{1,2})?$/', $normalizedAmount) !== 1) {
            throw PaymentReconciliationException::amountMismatch(trim($reference), '(on paper)', (string) $amount, (string) ($currency ?? '??'));
        }

        if (! is_string($currency) || trim($currency) === '') {
            throw PaymentReconciliationException::malformed('the payload carries no currency');
        }

        if (! is_string($statusWord) || trim($statusWord) === '') {
            throw PaymentReconciliationException::malformed('the payload carries no provider status word');
        }

        return PaymentCallbackData::fromInput(
            providerCode: $providerCode,
            externalReference: trim($reference),
            amount: $normalizedAmount,
            currency: trim($currency),
            providerStatus: $statusWord,
            failureReason: is_string($facts['failure_reason'] ?? null) ? $facts['failure_reason'] : null,
            signature: is_string($payload['signature'] ?? null) ? $payload['signature'] : null,
            payloadFingerprint: (string) ($payload['payload_fingerprint'] ?? PaymentWebhookData::fingerprintOf($providerCode, (string) ($payload['id'] ?? $payload['event_id'] ?? ''), $payload)),
            providerEventId: is_string($payload['id'] ?? null) ? $payload['id'] : (is_string($payload['event_id'] ?? null) ? $payload['event_id'] : null),
        );
    }

    /**
     * Apply a normalized, verified callback to internal state.
     *
     * @return array{payment: Payment, replayed: bool, applied_at: ?string}
     *
     * @throws PaymentReconciliationException
     */
    public function apply(PaymentCallbackData $callback): array
    {
        $rows = Payment::withTrashed()
            ->where('gateway', strtolower($callback->providerCode))
            ->where('gateway_reference', $callback->externalReference)
            ->limit(2)
            ->get();

        if ($rows->count() !== 1 || $rows->first()->trashed()) {
            throw PaymentReconciliationException::missingTransaction($callback->providerCode, $callback->externalReference);
        }

        /** @var Payment $payment */
        $payment = $rows->first();

        if (bccomp(self::moneyOf((string) $payment->amount), self::moneyOf($callback->amount), 2) !== 0) {
            throw PaymentReconciliationException::amountMismatch(
                $callback->externalReference,
                self::moneyOf((string) $payment->amount),
                self::moneyOf($callback->amount),
                ($payment->currency instanceof \BackedEnum ? (string) $payment->currency->value : (string) $payment->currency),
            );
        }

        if (strtoupper(($payment->currency instanceof \BackedEnum ? (string) $payment->currency->value : (string) $payment->currency)) !== $callback->currency) {
            throw PaymentReconciliationException::malformed(
                sprintf('currency fork: paper speaks %s, callback speaks %s', ($payment->currency instanceof \BackedEnum ? (string) $payment->currency->value : (string) $payment->currency), $callback->currency),
                ['reference' => $callback->externalReference],
            );
        }

        if ($payment->payable_type === Deposit::class) {
            $out = app(DepositCallbackApplicationService::class)->apply($callback, $payment);
        } elseif ($payment->payable_type === Withdrawal::class) {
            $out = app(WithdrawalCallbackApplicationService::class)->apply($callback, $payment);
        } else {
            throw PaymentReconciliationException::missingTransaction($callback->providerCode, $callback->externalReference);
        }

        if (! $out['replayed']) {
            $this->recordAudit($out['payment'], sprintf(
                'Callback applied: [%s/%s] → %s%s',
                $callback->providerCode,
                $callback->externalReference,
                $callback->providerStatus->value,
                $callback->failureReason instanceof PaymentFailureReason ? ' ('.$callback->failureReason->value.')' : '',
            ), RiskLevel::High);
        }

        return $out;
    }

    public static function moneyOf(string $amount): string
    {
        if (! extension_loaded('bcmath')) {
            throw new \RuntimeException('BCMath is required for exact monetary arithmetic.');
        }

        return bcadd($amount, '0', 2);
    }

    private function recordAudit(Payment $payment, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => (int) $payment->user_id,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => Payment::class,
            'auditable_id' => (int) $payment->getKey(),
            'description' => $description,
            'metadata' => [
                'payment_id' => (int) $payment->id,
                'gateway' => (string) $payment->gateway,
                'gateway_reference' => (string) ($payment->gateway_reference ?? ''),
                'lane' => 'payment-callback',
            ],
        ]);

        $log->save();
    }
}
