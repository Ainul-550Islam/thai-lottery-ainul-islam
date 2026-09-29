<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\PaymentCallbackData;
use App\Enums\AuditAction;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\RiskLevel;
use App\Exceptions\PaymentReconciliationException;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Withdrawal;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\WithdrawalCompletionService;
use Illuminate\Support\Facades\DB;

/**
 * Payment callback normalization + application.
 *
 * TRUST DISCIPLINE
 *   1. NOTHING FROM THE CLIENT BODY — this lane ingests ONLY provider
 *      envelopes already signature-verified. The caller of apply()
 *      must pass facts from PaymentCallbackData built off verified
 *      evidence.
 *   2. FACTS CHECKED, NOT ACCEPTED — the external reference resolves
 *      against the payments paper under the (gateway, reference) unique
 *      pair; amount and currency must agree by EXACT decimal;
 *      disagreements are pronounced refusals (reconciliation
 *      vocabulary), never guesses.
 *   3. MONEY NEVER MOVES HERE TWICE — the wallet-facing completion
 *      runs through the estate's OWN completion services with their
 *      own idempotency keys; a re-applied callback is arithmetic-free.
 *   4. INTERNAL STATUS IS THE ONLY STATUS — provider dialect is
 *      normalized ONCE (fromProviderWord) at the boundary and the
 *      dialect never leaks further.
 */
final class PaymentCallbackService
{
    public function __construct(
        private readonly DepositCompletionService $depositCompletion,
        private readonly WithdrawalCompletionService $withdrawalCompletion,
    ) {
    }

    /**
     * READ-ONLY status projection for browser-return pages (FINAL AUDIT #2).
     *
     * The browser return is a VIEW, never a state transition: this method
     * resolves the payments paper using only SAFE references (our own PAY-
     * reference, our own DP-/WD- document reference, or the gateway session
     * reference) and reports the state already recorded by verified
     * webhook processing or manual operations. It must not and cannot
     * credit, confirm, fail or cancel anything.
     *
     * A reference that belongs to somebody else is reported as not found:
     * existence of another player's payment is not disclosed.
     *
     * @return array{
     *     found: bool,
     *     reason?: string,
     *     payment?: \App\Models\Payment,
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
        $payment = null;

        if (is_string($paymentReference) && preg_match('/^PAY-/i', trim($paymentReference)) === 1) {
            $payment = \App\Models\Payment::query()->where('reference_number', trim($paymentReference))->first();
        } elseif (is_string($documentReference) && trim($documentReference) !== '') {
            $deposit = \App\Models\Deposit::query()->where('reference_number', trim($documentReference))->first();

            if ($deposit instanceof \App\Models\Deposit) {
                $payment = \App\Models\Payment::query()
                    ->where('payable_type', \App\Models\Deposit::class)
                    ->where('payable_id', $deposit->getKey())
                    ->first();
            } else {
                $withdrawal = \App\Models\Withdrawal::query()->where('reference_number', trim($documentReference))->first();

                if ($withdrawal instanceof \App\Models\Withdrawal) {
                    $payment = \App\Models\Payment::query()
                        ->where('payable_type', \App\Models\Withdrawal::class)
                        ->where('payable_id', $withdrawal->getKey())
                        ->first();
                }
            }
        } elseif (is_string($gatewayReference) && trim($gatewayReference) !== '') {
            $payment = \App\Models\Payment::query()->where('gateway_reference', trim($gatewayReference))->first();
        }

        if (! $payment instanceof \App\Models\Payment) {
            return ['found' => false, 'reason' => 'reference_not_found'];
        }

        // Ownership: another player's payment is indistinguishable from a
        // nonexistent one as far as this viewer is concerned.
        if ($viewerId !== null && (int) $payment->user_id !== $viewerId) {
            return ['found' => false, 'reason' => 'reference_not_found'];
        }

        $state = match ($payment->status) {
            \App\Enums\PaymentStatus::Captured => 'confirmed',
            \App\Enums\PaymentStatus::Failed => 'failed',
            \App\Enums\PaymentStatus::Cancelled => 'cancelled',
            \App\Enums\PaymentStatus::Refunded, \App\Enums\PaymentStatus::PartiallyRefunded, \App\Enums\PaymentStatus::Disputed => 'updated',
            \App\Enums\PaymentStatus::Pending, \App\Enums\PaymentStatus::Authorized => 'pending',
        };

        return [
            'found' => true,
            'payment' => $payment,
            'document_reference' => $payment->metadata['deposit_reference']
                ?? $payment->metadata['withdrawal_reference']
                ?? $payment->reference_number,
            'state' => $state,
            // "Paid" is proven ONLY by the internal captured status - never
            // by the URL, a query flag, or the route the browser landed on.
            'paid' => $payment->status === \App\Enums\PaymentStatus::Captured,
        ];
    }

    /* ------------------------------------------- normalization ------ */

    /**
     * Normalize a verified provider payload into a callback fact-set.
     * Reads the standard dialect shapes (flat and `data.object` nested)
     * WITHOUT trusting any of them — the caller re-proves identity by
     * resolving the external reference on the payments paper.
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

        if ($amount === null || ! (is_int($amount) || is_float($amount) || is_string($amount)) || ! preg_match('/^\d+(\.\d{1,2})?$/', is_string($amount) ? trim($amount) : number_format((float) $amount, 2, '.', ''))) {
            throw PaymentReconciliationException::amountMismatch(trim($reference), '(on paper)', (string) $amount, (string) ($currency ?? '??'));
        }

        $normalizedAmount = is_string($amount) ? trim($amount) : number_format((float) $amount, 2, '.', '');

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
            payloadFingerprint: (string) ($payload['payload_fingerprint'] ?? \App\DTOs\Payment\PaymentWebhookData::fingerprintOf($providerCode, (string) ($payload['id'] ?? $payload['event_id'] ?? ''), $payload)),
            providerEventId: is_string($payload['id'] ?? null) ? $payload['id'] : (is_string($payload['event_id'] ?? null) ? $payload['event_id'] : null),
        );
    }

    /* ---------------------------------------------- application ----- */

    /**
     * Apply a normalized, verified callback to internal state.
     * Idempotent at every layer: repeated application of the same
     * verified facts changes nothing (exactly-once by existing
     * determinism of the completion lanes).
     *
     * @return array{payment: Payment, replayed: bool, applied_at: ?string}
     *
     * @throws PaymentReconciliationException
     */
    public function apply(PaymentCallbackData $callback): array
    {
        return DB::transaction(function () use ($callback): array {
            /** @var Payment|null $payment */
            $payment = Payment::query()
                ->lockForUpdate()
                ->where('gateway', strtolower($callback->providerCode))
                ->where('gateway_reference', $callback->externalReference)
                ->first();

            if (! $payment instanceof Payment) {
                throw PaymentReconciliationException::missingTransaction($callback->providerCode, $callback->externalReference);
            }

            // FACTS PROVEN AGAINST THE PAPER, not against the caller.
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

            $replayed = in_array($payment->status instanceof PaymentStatus ? $payment->status : PaymentStatus::tryFrom((string) $payment->status), [PaymentStatus::Captured, PaymentStatus::Refunded], true)
                && in_array($callback->providerStatus, [PaymentTransactionStatus::Succeeded, PaymentTransactionStatus::Reversed], true);

            // Apply the provider's verdict — money through the estate's
            // own completion lanes, NEVER raw arithmetic.
            if ($callback->providerStatus === PaymentTransactionStatus::Succeeded && $payment->status !== PaymentStatus::Captured) {
                $payable = $payment->payable;

                if ($payable instanceof Deposit) {
                    $this->depositCompletion->complete($payable, $callback->callbackKey());
                } elseif ($payable instanceof Withdrawal) {
                    $this->withdrawalCompletion->complete($payable, $callback->callbackKey());
                }

                $payment->status = PaymentStatus::Captured;
                $payment->authorized_at = $payment->authorized_at ?? now();
                $payment->captured_at = now();
            } elseif ($callback->providerStatus === PaymentTransactionStatus::Failed && ! ($payment->status instanceof PaymentStatus ? $payment->status : PaymentStatus::tryFrom((string) $payment->status))->isFinal()) {
                $payment->status = PaymentStatus::Failed;
                $payment->failure_reason = ($callback->failureReason ?? PaymentFailureReason::Unknown)->value;
                $payment->failed_at = now();
            } elseif ($callback->providerStatus === PaymentTransactionStatus::Reversed && $payment->status === PaymentStatus::Captured) {
                $payment->status = PaymentStatus::Refunded;
            } elseif ($callback->providerStatus === PaymentTransactionStatus::Processing && $payment->status === PaymentStatus::Pending) {
                $payment->status = PaymentStatus::Authorized;
                $payment->authorized_at = now();
            } else {
                // No state change required — recorded as evidence only.
            }

            $metadata = is_array($payment->metadata) ? $payment->metadata : [];
            $metadata['provider_normalized'] = [
                'status' => $callback->providerStatus->value,
                'reason' => $callback->failureReason?->value,
                'fingerprint' => $callback->payloadFingerprint,
                'at' => now()->toIso8601String(),
            ];
            $payment->metadata = $metadata;
            $payment->save();

            $this->recordAudit($payment, sprintf(
                'Callback applied: [%s/%s] → %s%s',
                $callback->providerCode,
                $callback->externalReference,
                $callback->providerStatus->value,
                $callback->failureReason instanceof PaymentFailureReason ? ' ('.$callback->failureReason->value.')' : '',
            ), RiskLevel::High);

            return ['payment' => $payment, 'replayed' => $replayed, 'applied_at' => $metadata['provider_normalized']['at']];
        });
    }

    /* ------------------------------------------------- internals ---- */

    public static function moneyOf(string $amount): string
    {
        if (extension_loaded('bcmath')) {
            return bcadd($amount, '0', 2);
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private function recordAudit(Payment $payment, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

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
