<?php

declare(strict_types=1);

namespace App\Services\Payout\ThaiBank;

use App\DTOs\Payment\PayoutOutcome;
use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\PaymentStatus;
use App\Enums\RiskLevel;
use App\Enums\ThaiBankPayoutState;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\ThaiBankPayoutAttempt;
use App\Models\Withdrawal;
use App\Services\Audit\AuditLogService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WalletLockService;
use App\Services\Payment\PaymentLogSanitizer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Applies signed bank evidence under the existing wallet/withdrawal lock order. */
final class ThaiBankPayoutWebhookService
{
    public function __construct(
        private readonly WithdrawalCompletionService $completionService,
        private readonly WithdrawalApprovalService $approvalService,
        private readonly WalletLockService $walletLocks,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param array<string, mixed> $headers
     * @return array{accepted: bool, signature_verified: bool, duplicate?: bool}
     */
    public function verifyCallbackSignature(string $bank, string $rawBody, array $headers): bool
    {
        try {
            return $this->adapter($bank)->verifyCallback($rawBody, $headers);
        } catch (Throwable) {
            return false;
        }
    }

    public function processCallback(string $bank, string $rawBody, array $headers): array
    {
        try {
            $adapter = $this->adapter($bank);
        } catch (FinancialException) {
            return ['accepted' => false, 'signature_verified' => false];
        }
        if (! $this->verifyCallbackSignature($bank, $rawBody, $headers)) {
            return ['accepted' => false, 'signature_verified' => false];
        }

        try {
            $facts = $adapter->parseCallback($rawBody);
        } catch (FinancialException $exception) {
            $this->auditLogService->log(
                userId: null,
                action: AuditAction::SecurityAlert,
                riskLevel: RiskLevel::High,
                auditable: null,
                description: 'Signed Thai bank payout callback contained invalid or incomplete facts.',
                metadata: [
                    'bank' => strtolower($bank),
                    'body_sha256' => hash('sha256', $rawBody),
                    'reason_code' => $exception->errorCode(),
                ],
            );

            return ['accepted' => false, 'signature_verified' => true];
        }
        $reference = (string) $facts['reference'];
        $providerReference = is_string($facts['providerReference'] ?? null) ? $facts['providerReference'] : null;
        $matches = ThaiBankPayoutAttempt::query()
            ->where('withdrawal_reference', $reference)
            ->orWhere('provider_reference', $reference)
            ->when($providerReference !== null, fn ($query) => $query->orWhere('provider_reference', $providerReference))
            ->limit(2)
            ->get();
        if ($matches->count() !== 1) {
            $this->auditLogService->log(
                userId: null,
                action: $matches->isEmpty() ? AuditAction::Reconcile : AuditAction::SecurityAlert,
                riskLevel: $matches->isEmpty() ? RiskLevel::Medium : RiskLevel::High,
                auditable: null,
                description: $matches->isEmpty() ? 'Signed Thai bank payout callback referenced no local attempt.' : 'Signed Thai bank callback matched multiple local payout attempts.',
                metadata: [
                    'bank' => strtolower($bank),
                    'reference_hash' => hash('sha256', $reference),
                    'provider_reference_hash' => $providerReference !== null ? hash('sha256', $providerReference) : null,
                    'match_count' => $matches->count(),
                ],
            );

            return ['accepted' => true, 'signature_verified' => true];
        }

        $attempt = $matches->first();
        if (! $attempt instanceof ThaiBankPayoutAttempt) {
            return ['accepted' => true, 'signature_verified' => true];
        }
        if (strtolower(trim($attempt->bank)) !== strtolower(trim($bank))) {
            $this->audit($attempt, AuditAction::SecurityAlert, RiskLevel::High, 'Signed Thai bank callback bank does not match the linked payout attempt.', [
                'source' => 'webhook',
                'callback_bank' => strtolower(trim($bank)),
                'attempt_bank' => strtolower(trim($attempt->bank)),
                'reference_hash' => hash('sha256', $reference),
            ]);

            return ['accepted' => true, 'signature_verified' => true];
        }
        $state = self::stateFromProvider((string) $facts['state']);
        $outcome = new PayoutOutcome(
            state: $state,
            providerReference: $providerReference,
            message: null,
            rawSanitised: array_filter([
                'provider_status' => substr((string) $facts['state'], 0, 40),
                'amount' => $facts['amount'] ?? null,
                'currency' => $facts['currency'] ?? null,
                'provider_reference_hash' => $providerReference !== null ? hash('sha256', $providerReference) : null,
            ], static fn (mixed $value): bool => $value !== null),
        );
        $applied = $this->applyOutcome($attempt, $outcome, $facts['amount'] ?? null, $facts['currency'] ?? null, 'webhook');

        return ['accepted' => true, 'signature_verified' => true, 'duplicate' => ! $applied];
    }

    /** Shared by verified webhooks and the status poller. */
    public function applyProviderOutcome(ThaiBankPayoutAttempt $attempt, PayoutOutcome $outcome, string $source = 'poller'): bool
    {
        $amount = $outcome->rawSanitised['amount'] ?? null;
        $currency = $outcome->rawSanitised['currency'] ?? null;

        return $this->applyOutcome($attempt, $outcome, is_string($amount) || is_int($amount) ? (string) $amount : null,
            is_string($currency) ? strtoupper($currency) : null, $source);
    }

    private function applyOutcome(ThaiBankPayoutAttempt $attempt, PayoutOutcome $outcome, ?string $reportedAmount, ?string $reportedCurrency, string $source): bool
    {
        $attemptSnapshot = ThaiBankPayoutAttempt::query()->find($attempt->getKey());
        if (! $attemptSnapshot instanceof ThaiBankPayoutAttempt) {
            return false;
        }
        $withdrawalSnapshot = Withdrawal::query()->find($attemptSnapshot->withdrawal_id);
        if (! $withdrawalSnapshot instanceof Withdrawal) {
            $this->audit($attemptSnapshot, AuditAction::Reconcile, RiskLevel::High, 'Thai bank payout attempt has no linked withdrawal.', ['source' => $source]);

            return false;
        }

        try {
            return DB::transaction(function () use ($attemptSnapshot, $withdrawalSnapshot, $outcome, $reportedAmount, $reportedCurrency, $source): bool {
                // Preserve the shared finance lock order: WALLET -> WITHDRAWAL -> payout-attempt.
                $wallet = $this->walletLocks->lock((int) $withdrawalSnapshot->wallet_id);
                $withdrawal = Withdrawal::query()->lockForUpdate()->find($withdrawalSnapshot->getKey());
                $current = ThaiBankPayoutAttempt::query()->lockForUpdate()->find($attemptSnapshot->getKey());
                if (! $withdrawal instanceof Withdrawal || ! $current instanceof ThaiBankPayoutAttempt
                    || (int) $current->withdrawal_id !== (int) $withdrawal->getKey()
                    || (int) $wallet->getKey() !== (int) $withdrawal->wallet_id) {
                    $this->audit($attemptSnapshot, AuditAction::Reconcile, RiskLevel::High, 'Thai bank payout callback could not establish a consistent local withdrawal.', ['source' => $source]);

                    return false;
                }

                if ($current->state->isTerminal()) {
                    if ($current->state === $outcome->state) {
                        $amountMalformed = $reportedAmount !== null
                            && preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/D', $reportedAmount) !== 1;
                        $amountMismatch = $reportedAmount !== null && ! $amountMalformed
                            && bccomp($reportedAmount, (string) $current->amount, 2) !== 0;
                        $currencyMismatch = $reportedCurrency !== null && strtoupper($reportedCurrency) !== Currency::THB->value;
                        $referenceMismatch = $outcome->providerReference !== null
                            && $current->provider_reference !== null
                            && ! hash_equals((string) $current->provider_reference, $outcome->providerReference);
                        if ($amountMalformed || $amountMismatch || $currencyMismatch || $referenceMismatch
                            || ($current->state === ThaiBankPayoutState::Settled && ($reportedAmount === null || $reportedCurrency === null))) {
                            $this->audit($current, AuditAction::Reconcile, RiskLevel::High, 'Conflicting duplicate Thai bank evidence was ignored after terminal settlement.', [
                                'source' => $source,
                                'terminal_state' => $current->state->value,
                                'amount_malformed' => $amountMalformed,
                                'amount_mismatch' => $amountMismatch,
                                'currency_mismatch' => $currencyMismatch,
                                'provider_reference_mismatch' => $referenceMismatch,
                            ]);
                        }

                        return false;
                    }
                    $this->audit($current, AuditAction::Reconcile, RiskLevel::High, 'Conflicting Thai bank evidence cannot change a terminal payout state.', [
                        'source' => $source,
                        'terminal_state' => $current->state->value,
                        'reported_state' => $outcome->state->value,
                    ]);

                    return false;
                }

                if ($outcome->providerReference !== null && $current->provider_reference !== null
                    && ! hash_equals((string) $current->provider_reference, $outcome->providerReference)) {
                    $current->forceFill([
                        'state' => ThaiBankPayoutState::Uncertain,
                        'outcome_unknown' => true,
                        'raw_response' => PaymentLogSanitizer::sanitize(array_merge($outcome->rawSanitised, [
                            'provider_reference_mismatch' => true,
                        ])),
                    ])->save();
                    $this->audit($current, AuditAction::Reconcile, RiskLevel::High, 'Thai bank callback provider reference conflicted with the reserved payout attempt; hold preserved.', [
                        'source' => $source,
                        'expected_provider_reference_hash' => hash('sha256', (string) $current->provider_reference),
                        'reported_provider_reference_hash' => hash('sha256', $outcome->providerReference),
                    ]);

                    return false;
                }

                if ($outcome->state === ThaiBankPayoutState::Settled) {
                    $expectedAmount = (string) $current->amount;
                    if ($reportedAmount === null || $reportedCurrency === null
                        || strtoupper($reportedCurrency) !== Currency::THB->value
                        || preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/D', $reportedAmount) !== 1
                        || bccomp($reportedAmount, $expectedAmount, 2) !== 0) {
                        $current->forceFill([
                            'state' => ThaiBankPayoutState::Uncertain,
                            'outcome_unknown' => true,
                            'raw_response' => PaymentLogSanitizer::sanitize(array_merge($outcome->rawSanitised, [
                                'mismatch' => true,
                                'reported_amount' => $reportedAmount,
                                'reported_currency' => $reportedCurrency,
                            ])),
                        ])->save();
                        $this->audit($current, AuditAction::Reconcile, RiskLevel::High, 'Thai bank settled evidence had a missing or mismatched amount/currency; hold preserved.', [
                            'source' => $source,
                            'expected_amount' => $expectedAmount,
                            'reported_amount' => $reportedAmount,
                            'reported_currency' => $reportedCurrency,
                        ]);

                        return false;
                    }
                    $providerReference = $outcome->providerReference ?? $current->provider_reference;
                    if (! is_string($providerReference) || $providerReference === '') {
                        $current->forceFill(['state' => ThaiBankPayoutState::Uncertain, 'outcome_unknown' => true])->save();
                        $this->audit($current, AuditAction::Reconcile, RiskLevel::High, 'Thai bank settlement lacked a provider reference; hold preserved.', ['source' => $source]);

                        return false;
                    }
                    $this->markPaymentCaptured($withdrawal, $current, $providerReference, $source);
                    $this->completionService->complete($withdrawal, options: [
                        'provider' => $withdrawal->method->value,
                        'provider_reference' => $providerReference,
                        'description' => 'Thai bank payout '.$withdrawal->reference_number,
                        'metadata' => ['payout_rail' => 'thai_bank', 'payout_attempt_id' => (int) $current->getKey(), 'evidence_source' => $source],
                    ]);
                    $current->forceFill([
                        'state' => ThaiBankPayoutState::Settled,
                        'provider_reference' => $providerReference,
                        'outcome_unknown' => false,
                        'raw_response' => PaymentLogSanitizer::sanitize($outcome->rawSanitised),
                    ])->save();
                    $this->audit($current, AuditAction::Payout, RiskLevel::Medium, 'Thai bank payout settled from verified provider evidence.', [
                        'source' => $source,
                        'withdrawal_id' => (int) $withdrawal->getKey(),
                        'amount' => $expectedAmount,
                        'provider_reference_hash' => hash('sha256', $providerReference),
                    ]);

                    return true;
                }

                if ($outcome->state === ThaiBankPayoutState::Rejected) {
                    $rejectionAmountMalformed = $reportedAmount !== null
                        && preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/D', $reportedAmount) !== 1;
                    $rejectionAmountMismatch = $reportedAmount !== null && ! $rejectionAmountMalformed
                        && bccomp($reportedAmount, (string) $current->amount, 2) !== 0;
                    $rejectionCurrencyMismatch = $reportedCurrency !== null
                        && strtoupper($reportedCurrency) !== Currency::THB->value;
                    if ($rejectionAmountMalformed || $rejectionAmountMismatch || $rejectionCurrencyMismatch) {
                        $current->forceFill([
                            'state' => ThaiBankPayoutState::Uncertain,
                            'outcome_unknown' => true,
                            'raw_response' => PaymentLogSanitizer::sanitize(array_merge($outcome->rawSanitised, ['mismatch' => true])),
                        ])->save();
                        $this->audit($current, AuditAction::Reconcile, RiskLevel::High, 'Thai bank rejection evidence had malformed or mismatched amount/currency; hold preserved.', [
                            'source' => $source,
                            'amount_malformed' => $rejectionAmountMalformed,
                            'amount_mismatch' => $rejectionAmountMismatch,
                            'currency_mismatch' => $rejectionCurrencyMismatch,
                        ]);

                        return false;
                    }
                    $this->markPaymentFailed($withdrawal, $current, $outcome->providerReference ?? $current->provider_reference, $source);
                    $this->approvalService->markProviderFailed($withdrawal);
                    $current->forceFill([
                        'state' => ThaiBankPayoutState::Rejected,
                        'provider_reference' => $outcome->providerReference ?? $current->provider_reference,
                        'outcome_unknown' => false,
                        'raw_response' => PaymentLogSanitizer::sanitize($outcome->rawSanitised),
                    ])->save();
                    $this->audit($current, AuditAction::Payout, RiskLevel::Medium, 'Thai bank payout rejection released the reserved hold.', [
                        'source' => $source,
                        'withdrawal_id' => (int) $withdrawal->getKey(),
                    ]);

                    return true;
                }

                $state = in_array($outcome->state, [ThaiBankPayoutState::Prepared, ThaiBankPayoutState::Accepted, ThaiBankPayoutState::Uncertain], true)
                    ? $outcome->state
                    : ThaiBankPayoutState::Uncertain;
                $current->forceFill([
                    'state' => $state,
                    'provider_reference' => $outcome->providerReference ?? $current->provider_reference,
                    'outcome_unknown' => $state === ThaiBankPayoutState::Uncertain,
                    'raw_response' => PaymentLogSanitizer::sanitize($outcome->rawSanitised),
                ])->save();
                if ($state === ThaiBankPayoutState::Uncertain) {
                    $this->audit($current, AuditAction::Reconcile, RiskLevel::High, 'Thai bank payout status remained ambiguous; hold retained for reconciliation.', array_filter([
                        'source' => $source,
                        'provider_status' => is_string($outcome->rawSanitised['provider_status'] ?? null)
                            ? substr($outcome->rawSanitised['provider_status'], 0, 40)
                            : null,
                        'reported_amount' => $reportedAmount,
                        'reported_currency' => $reportedCurrency,
                    ], static fn (mixed $value): bool => $value !== null));
                }

                return true;
            }, 3);
        } catch (Throwable $exception) {
            $this->markUncertainAfterFailure($attemptSnapshot, $source, $exception);

            return false;
        }
    }

    private function markPaymentCaptured(Withdrawal $withdrawal, ThaiBankPayoutAttempt $attempt, string $providerReference, string $source): void
    {
        $payment = Payment::withTrashed()
            ->where('payable_type', Withdrawal::class)
            ->where('payable_id', $withdrawal->getKey())
            ->lockForUpdate()
            ->first();
        if (! $payment instanceof Payment) {
            return;
        }
        if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Authorized, PaymentStatus::Captured], true)
            || ($payment->gateway_reference !== null && $payment->gateway_reference !== $providerReference)) {
            throw FinancialException::withCode('thai_payout_payment_state_conflict', 'The linked payout payment conflicts with settled bank evidence.');
        }

        $payment->forceFill([
            'status' => PaymentStatus::Captured,
            'captured_at' => $payment->captured_at ?? now(),
            'gateway_reference' => $providerReference,
            'gateway_response' => [
                'gateway' => 'thai_bank',
                'outcome' => 'completed',
                'provider_reference' => $providerReference,
                'payout_rail' => 'thai_bank',
                'payout_attempt_id' => (int) $attempt->getKey(),
                'evidence_source' => $source,
            ],
        ])->save();
    }

    private function markPaymentFailed(Withdrawal $withdrawal, ThaiBankPayoutAttempt $attempt, ?string $providerReference, string $source): void
    {
        $payment = Payment::withTrashed()
            ->where('payable_type', Withdrawal::class)
            ->where('payable_id', $withdrawal->getKey())
            ->lockForUpdate()
            ->first();
        if (! $payment instanceof Payment) {
            return;
        }
        if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Authorized, PaymentStatus::Failed], true)
            || ($providerReference !== null && $payment->gateway_reference !== null && $payment->gateway_reference !== $providerReference)) {
            throw FinancialException::withCode('thai_payout_payment_state_conflict', 'The linked payout payment conflicts with bank rejection evidence.');
        }

        $payment->forceFill([
            'status' => PaymentStatus::Failed,
            'failed_at' => $payment->failed_at ?? now(),
            'failure_reason' => 'provider_rejected',
            'gateway_reference' => $providerReference ?? $payment->gateway_reference,
            'gateway_response' => [
                'gateway' => 'thai_bank',
                'outcome' => 'failed',
                'state' => 'rejected',
                'provider_reference' => $providerReference,
                'payout_rail' => 'thai_bank',
                'payout_attempt_id' => (int) $attempt->getKey(),
                'evidence_source' => $source,
            ],
        ])->save();
    }

    private function markUncertainAfterFailure(ThaiBankPayoutAttempt $attempt, string $source, Throwable $exception): void
    {
        DB::transaction(function () use ($attempt, $source, $exception): void {
            $current = ThaiBankPayoutAttempt::query()->lockForUpdate()->find($attempt->getKey());
            if (! $current instanceof ThaiBankPayoutAttempt || $current->state->isTerminal()) {
                return;
            }
            $current->forceFill([
                'state' => ThaiBankPayoutState::Uncertain,
                'outcome_unknown' => true,
                'raw_response' => PaymentLogSanitizer::sanitize(array_merge(is_array($current->raw_response) ? $current->raw_response : [], [
                    'application_error' => $exception::class,
                    'source' => $source,
                ])),
            ])->save();
            $this->audit($current, AuditAction::Reconcile, RiskLevel::High, 'Thai bank payout evidence processing failed; hold retained for reconciliation.', [
                'source' => $source,
                'exception_class' => $exception::class,
            ]);
        }, 3);
    }

    private function adapter(string $bank): ThaiBankPayoutAdapterInterface
    {
        return match (strtolower(trim($bank))) {
            'scb' => app(ScbPayoutAdapter::class),
            'kbank' => app(KbankPayoutAdapter::class),
            default => throw FinancialException::withCode('thai_payout_bank_unsupported', 'The requested Thai bank is not supported.'),
        };
    }

    private static function stateFromProvider(string $state): ThaiBankPayoutState
    {
        return match (strtoupper(trim($state))) {
            'ACCEPTED', 'PENDING', 'PROCESSING', 'QUEUED' => ThaiBankPayoutState::Accepted,
            'SETTLED', 'COMPLETED', 'SUCCESS', 'SUCCEEDED', 'SUCCESSFUL' => ThaiBankPayoutState::Settled,
            'REJECTED', 'FAILED', 'DECLINED' => ThaiBankPayoutState::Rejected,
            default => ThaiBankPayoutState::Uncertain,
        };
    }

    /** @param array<string, mixed> $metadata */
    private function audit(ThaiBankPayoutAttempt $attempt, AuditAction $action, RiskLevel $risk, string $description, array $metadata): void
    {
        $this->auditLogService->log(
            userId: null,
            action: $action,
            riskLevel: $risk,
            auditable: $attempt,
            description: $description,
            metadata: PaymentLogSanitizer::sanitize(array_merge($metadata, [
                'withdrawal_id' => (int) $attempt->withdrawal_id,
                'bank' => $attempt->bank,
                'withdrawal_reference_hash' => hash('sha256', (string) $attempt->withdrawal_reference),
            ])),
        );
    }
}
