<?php

declare(strict_types=1);

namespace App\Services\Payment\Drivers;

use App\DTOs\Payment\GatewayDepositResponse;
use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\Payment\PayoutInstruction;
use App\DTOs\Payment\PayoutOutcome;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\RiskLevel;
use App\Enums\ThaiBankPayoutState;
use App\Enums\WebhookEventType;
use App\Exceptions\FinancialException;
use App\Jobs\PollThaiBankPayoutStatusJob;
use App\Models\Deposit;
use App\Models\ThaiBankPayoutAttempt;
use App\Models\Withdrawal;
use App\Services\Audit\AuditLogService;
use App\Services\Finance\Money;
use App\Services\Lock\DistributedLockService;
use App\Services\Payment\PaymentLogSanitizer;
use App\Services\Payout\ThaiBank\KbankPayoutAdapter;
use App\Services\Payout\ThaiBank\ScbPayoutAdapter;
use App\Services\Payout\ThaiBank\ThaiBankDestinationValidator;
use App\Services\Payout\ThaiBank\ThaiBankPayoutAdapterInterface;
use App\Services\Payout\ThaiBank\ThaiBankPayoutWebhookService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Internal, payout-only Thai bank rail, resolved by the `thai_bank` gateway key.
 * PaymentMethod deliberately has no such case: players choose bank_transfer or
 * promptpay, and WithdrawalDisbursementService persists metadata.gateway_key
 * before dispatch. Provider/reference ownership remains scoped to the method;
 * reconciliation and polling use the durable payout attempt and never resend.
 */
final class ThaiBankPayoutGateway extends AbstractPaymentGateway
{
    public function __construct(
        ConfigRepository $config,
        private readonly ThaiBankPayoutAdapterInterface $adapter,
        private readonly ThaiBankDestinationValidator $destinationValidator,
        private readonly DistributedLockService $lockService,
        private readonly AuditLogService $auditLogService,
    ) {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'thai_bank';
    }

    public function label(): string
    {
        return 'Thai bank transfer';
    }

    public function status(): GatewayIntegrationStatus
    {
        return $this->isEnabled() ? GatewayIntegrationStatus::FullyImplemented : GatewayIntegrationStatus::ConfiguredOnly;
    }

    public function isEnabled(): bool
    {
        return (bool) config('thai_payout.enabled', false)
            && (bool) config('thai_payout.banks.'.$this->adapter->bank().'.enabled', false)
            && $this->adapterConfigured();
    }

    public function supportsDeposit(): bool
    {
        return false;
    }

    public function supportsWithdrawal(): bool
    {
        return true;
    }

    public function supportsWebhook(): bool
    {
        return true;
    }

    public function supportsCurrency(Currency $currency): bool
    {
        return $currency === Currency::THB;
    }

    public function initiateDeposit(Deposit $deposit, array $options = []): GatewayDepositResponse
    {
        return GatewayDepositResponse::failed(
            errorMessage: 'Thai bank payout gateway does not accept deposits.',
            rawResponse: [],
            metadata: ['gateway' => $this->name(), 'reason' => 'deposit_not_supported'],
        );
    }

    public function initiateWithdrawal(Withdrawal $withdrawal, array $options = []): GatewayWithdrawalResponse
    {
        if (! $this->isEnabled()) {
            return GatewayWithdrawalResponse::failed(
                errorMessage: 'Thai bank payouts are not configured or enabled.',
                rawResponse: [],
                metadata: ['gateway' => $this->name(), 'reason' => 'not_configured'],
            );
        }
        if (! in_array($withdrawal->status->value, ['approved', 'processing', 'completed'], true)) {
            return GatewayWithdrawalResponse::failed(
                errorMessage: 'The withdrawal is not approved for payout.',
                rawResponse: [],
                metadata: ['gateway' => $this->name(), 'reason' => 'withdrawal_not_approved'],
            );
        }

        try {
            $amount = Money::of((string) $withdrawal->amount, Currency::THB)->toString();
            $details = is_array($withdrawal->payout_details) ? $withdrawal->payout_details : [];
            $instruction = $this->destinationValidator->instruction(
                reference: (string) $withdrawal->reference_number,
                amount: $amount,
                currency: $withdrawal->currency->value,
                details: $details,
            );
        } catch (FinancialException $exception) {
            Log::warning('payment.thai_bank_payout_destination_refused', PaymentLogSanitizer::sanitize([
                'withdrawal_id' => (int) $withdrawal->getKey(),
                'withdrawal_reference' => (string) $withdrawal->reference_number,
                'error_code' => $exception->errorCode(),
                'reason' => $exception->contextValue('reason'),
            ]));

            return GatewayWithdrawalResponse::failed(
                errorMessage: $exception->getMessage(),
                rawResponse: [],
                metadata: ['gateway' => $this->name(), 'reason' => $exception->errorCode()],
            );
        } catch (Throwable) {
            return GatewayWithdrawalResponse::failed(
                errorMessage: 'The withdrawal amount or destination is invalid; no transfer was submitted.',
                rawResponse: [],
                metadata: ['gateway' => $this->name(), 'reason' => 'invalid_instruction'],
            );
        }

        $bank = $this->adapter->bank();
        if ($bank === 'kbank' && $instruction->destinationType !== 'bank_account') {
            return GatewayWithdrawalResponse::failed(
                errorMessage: 'KBank Corporate Fund Transfer accepts bank-account destinations only.',
                rawResponse: [],
                metadata: ['gateway' => $this->name(), 'reason' => 'promptpay_not_supported_by_kbank'],
            );
        }
        if ($withdrawal->status->value === 'completed') {
            $attempt = ThaiBankPayoutAttempt::query()->where('withdrawal_reference', $instruction->reference)->first();
            if (! $attempt instanceof ThaiBankPayoutAttempt) {
                return GatewayWithdrawalResponse::failed(
                    errorMessage: 'The completed withdrawal has no Thai bank payout attempt to replay.',
                    rawResponse: [],
                    metadata: ['gateway' => $this->name(), 'reason' => 'completed_attempt_missing'],
                );
            }
            $fingerprint = self::instructionFingerprint($instruction, $bank);
            if ((int) $attempt->withdrawal_id !== (int) $withdrawal->getKey()
                || ! hash_equals((string) $attempt->request_fingerprint, $fingerprint)) {
                return $this->referenceConflictResponse($withdrawal, $instruction->reference, $fingerprint);
            }

            return $this->storedOutcome($attempt);
        }
        $lockSuffix = hash('sha256', (string) $withdrawal->reference_number);
        try {
            return $this->lockService->runWithLock(
                'thai-payout:daily:'.now()->toDateString(),
                fn (): GatewayWithdrawalResponse => $this->lockService->runWithLock(
                    'thai-payout:withdrawal:'.$lockSuffix,
                    fn (): GatewayWithdrawalResponse => $this->initiateOnce($withdrawal, $instruction, $bank),
                    ttlSeconds: 30,
                    maxAttempts: 2,
                ),
                ttlSeconds: 60,
                maxAttempts: 2,
            );
        } catch (Throwable $exception) {
            Log::warning('payment.thai_bank_payout_lock_failed', PaymentLogSanitizer::sanitize([
                'withdrawal_id' => (int) $withdrawal->getKey(),
                'withdrawal_reference_hash' => hash('sha256', (string) $withdrawal->reference_number),
                'exception_class' => $exception::class,
            ]));
            try {
                $this->auditLogService->log(
                    userId: null,
                    action: AuditAction::Reconcile,
                    riskLevel: RiskLevel::High,
                    auditable: $withdrawal,
                    description: 'Thai bank payout could not acquire a dispatch lock; the hold remains for reconciliation and no retry is issued.',
                    metadata: PaymentLogSanitizer::sanitize([
                        'withdrawal_id' => (int) $withdrawal->getKey(),
                        'withdrawal_reference_hash' => hash('sha256', (string) $withdrawal->reference_number),
                        'reason' => 'lock_unavailable',
                    ]),
                );
            } catch (Throwable $auditException) {
                Log::critical('payment.thai_bank_payout_lock_audit_failed', PaymentLogSanitizer::sanitize([
                    'withdrawal_id' => (int) $withdrawal->getKey(),
                    'exception_class' => $auditException::class,
                ]));
            }

            return GatewayWithdrawalResponse::uncertain(
                providerReference: null,
                rawResponse: [],
                metadata: ['gateway' => $this->name(), 'reason' => 'lock_unavailable'],
            );
        }
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $bank = strtolower((string) ($request->route('bank') ?? $this->adapter->bank()));

        return app(ThaiBankPayoutWebhookService::class)->verifyCallbackSignature(
            $bank,
            $request->getContent(),
            $request->headers->all(),
        );
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        $bank = strtolower((string) ($request->route('bank') ?? $this->adapter->bank()));
        $facts = $this->adapterFor($bank)->parseCallback($request->getContent());
        $state = strtoupper($facts['state']);
        $eventType = match ($state) {
            'SETTLED', 'COMPLETED', 'SUCCESS', 'SUCCEEDED', 'SUCCESSFUL' => WebhookEventType::WithdrawalSuccess,
            'REJECTED', 'FAILED', 'DECLINED' => WebhookEventType::WithdrawalFailed,
            default => WebhookEventType::Unknown,
        };
        $currency = isset($facts['currency']) && is_string($facts['currency']) ? Currency::tryFrom(strtoupper($facts['currency'])) : null;

        return new WebhookPayload(
            gateway: $this->name(),
            eventId: hash('sha256', $request->getContent()),
            eventType: $eventType,
            providerReference: $facts['providerReference'],
            internalReference: $facts['reference'],
            amount: $facts['amount'] ?? null,
            currency: $currency,
            isSuccess: $eventType === WebhookEventType::WithdrawalSuccess,
            failureReason: $eventType === WebhookEventType::WithdrawalFailed ? 'provider_rejected' : null,
            rawData: PaymentLogSanitizer::sanitize([
                'state' => $facts['state'],
                'provider_reference' => $facts['providerReference'],
                'amount' => $facts['amount'] ?? null,
                'currency' => $facts['currency'] ?? null,
            ]),
            metadata: ['bank' => $bank],
        );
    }

    private function initiateOnce(Withdrawal $withdrawal, PayoutInstruction $instruction, string $bank): GatewayWithdrawalResponse
    {
        $fingerprint = self::instructionFingerprint($instruction, $bank);
        try {
            $reservation = DB::transaction(function () use ($withdrawal, $instruction, $bank, $fingerprint): array {
                $existing = ThaiBankPayoutAttempt::query()
                    ->where('withdrawal_reference', $instruction->reference)
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof ThaiBankPayoutAttempt) {
                    if ((int) $existing->withdrawal_id !== (int) $withdrawal->getKey()
                        || ! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                        throw FinancialException::withCode('thai_payout_reference_conflict', 'This withdrawal reference already has a different payout instruction; reconciliation is required.');
                    }

                    return ['attempt' => $existing, 'created' => false];
                }
                $this->assertAmountLimits($instruction->amount);
                $created = ThaiBankPayoutAttempt::query()->create([
                    'withdrawal_id' => (int) $withdrawal->getKey(),
                    'withdrawal_reference' => $instruction->reference,
                    'bank' => $bank,
                    'amount' => $instruction->amount,
                    'destination_type' => $instruction->destinationType,
                    'destination_masked' => ThaiBankDestinationValidator::maskAccount($instruction->accountNumber),
                    'state' => ThaiBankPayoutState::Prepared,
                    'request_fingerprint' => $fingerprint,
                    'outcome_unknown' => false,
                    'raw_response' => [],
                ]);

                return ['attempt' => $created, 'created' => true];
            }, 3);
        } catch (QueryException $exception) {
            $existing = ThaiBankPayoutAttempt::query()->where('withdrawal_reference', $instruction->reference)->first();
            if ($existing instanceof ThaiBankPayoutAttempt) {
                if ((int) $existing->withdrawal_id !== (int) $withdrawal->getKey()
                    || ! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                    return $this->referenceConflictResponse($withdrawal, $instruction->reference, $fingerprint);
                }

                return $this->returnStoredAttemptWithoutDispatch($existing);
            }

            return GatewayWithdrawalResponse::uncertain(
                providerReference: null,
                rawResponse: [],
                metadata: ['gateway' => $this->name(), 'reason' => 'attempt_reservation_conflict'],
            );
        } catch (FinancialException $exception) {
            if ($exception->errorCode() === 'thai_payout_reference_conflict') {
                return $this->referenceConflictResponse($withdrawal, $instruction->reference, $fingerprint);
            }

            return GatewayWithdrawalResponse::failed(
                errorMessage: $exception->getMessage(),
                rawResponse: [],
                metadata: ['gateway' => $this->name(), 'reason' => $exception->errorCode()],
            );
        }

        /** @var ThaiBankPayoutAttempt $attempt */
        $attempt = $reservation['attempt'];
        if (! $reservation['created']) {
            return $this->returnStoredAttemptWithoutDispatch($attempt);
        }
        if ($attempt->state !== ThaiBankPayoutState::Prepared) {
            $this->schedulePoll($attempt);

            return $this->storedOutcome($attempt);
        }

        // Commit an unknown/in-flight marker BEFORE the external request. If the
        // process dies at any point around the network call, the unique attempt
        // can never authorize a second transfer invocation.
        $attempt = DB::transaction(function () use ($attempt): ThaiBankPayoutAttempt {
            $current = ThaiBankPayoutAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());
            if ($current->state !== ThaiBankPayoutState::Prepared) {
                return $current;
            }
            $current->forceFill([
                'state' => ThaiBankPayoutState::Uncertain,
                'outcome_unknown' => true,
                'raw_response' => ['dispatch' => 'started_or_unknown'],
            ])->save();

            return $current;
        }, 3);
        if ($attempt->state !== ThaiBankPayoutState::Uncertain) {
            return $this->storedOutcome($attempt);
        }
        $this->auditUncertainAttempt($attempt, 'dispatch_started_or_unknown', 'Thai bank payout dispatch started; the provider outcome must be confirmed before releasing or completing the hold.');

        try {
            $outcome = $this->adapter->initiateTransfer($instruction);
        } catch (Throwable) {
            $outcome = PayoutOutcome::uncertain(rawSanitised: ['transport' => 'unavailable']);
        }
        if ($outcome->state === ThaiBankPayoutState::Prepared) {
            $outcome = PayoutOutcome::uncertain(
                providerReference: $outcome->providerReference,
                rawSanitised: PaymentLogSanitizer::sanitize(array_merge($outcome->rawSanitised, ['invalid_post_dispatch_state' => 'prepared'])),
            );
        }
        if ($outcome->state === ThaiBankPayoutState::Settled) {
            $reportedAmount = $outcome->rawSanitised['amount'] ?? null;
            $reportedCurrency = $outcome->rawSanitised['currency'] ?? null;
            if (! is_string($outcome->providerReference) || $outcome->providerReference === ''
                || (! is_string($reportedAmount) && ! is_int($reportedAmount))
                || ! is_string($reportedCurrency) || strtoupper($reportedCurrency) !== Currency::THB->value
                || preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/D', (string) $reportedAmount) !== 1
                || bccomp((string) $reportedAmount, $instruction->amount, 2) !== 0) {
                $outcome = PayoutOutcome::uncertain(
                    providerReference: $outcome->providerReference,
                    rawSanitised: PaymentLogSanitizer::sanitize(array_merge($outcome->rawSanitised, ['settlement_evidence_mismatch' => true])),
                );
            }
        }
        if ($outcome->state === ThaiBankPayoutState::Settled) {
            try {
                app(ThaiBankPayoutWebhookService::class)->applyProviderOutcome($attempt, $outcome, 'initial_dispatch');
                $attempt = ThaiBankPayoutAttempt::query()->findOrFail($attempt->getKey());
            } catch (Throwable) {
                $outcome = PayoutOutcome::uncertain(
                    providerReference: $outcome->providerReference,
                    rawSanitised: PaymentLogSanitizer::sanitize(array_merge($outcome->rawSanitised, [
                        'initial_settlement_processing_failed' => true,
                    ])),
                );
                $attempt = $this->persistOutcome($attempt, $outcome);
            }
        } else {
            $attempt = $this->persistOutcome($attempt, $outcome);
        }
        if ($attempt->state === ThaiBankPayoutState::Uncertain) {
            $this->auditUncertainAttempt($attempt, 'provider_outcome_uncertain', 'Thai bank payout provider response was uncertain; the hold remains reserved for reconciliation.', [
                'http_status' => $outcome->rawSanitised['http_status'] ?? null,
                'settlement_evidence_mismatch' => $outcome->rawSanitised['settlement_evidence_mismatch'] ?? null,
            ]);
        }
        if ($attempt->state !== $outcome->state) {
            return $this->storedOutcome($attempt);
        }
        $this->schedulePoll($attempt);

        return $this->mapOutcome($outcome);
    }

    private function returnStoredAttemptWithoutDispatch(ThaiBankPayoutAttempt $attempt): GatewayWithdrawalResponse
    {
        $recoveredPrepared = false;
        if ($attempt->state === ThaiBankPayoutState::Prepared) {
            $resolution = DB::transaction(function () use ($attempt): array {
                $current = ThaiBankPayoutAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());
                if ($current->state !== ThaiBankPayoutState::Prepared) {
                    return ['attempt' => $current, 'recovered' => false];
                }
                $current->forceFill([
                    'state' => ThaiBankPayoutState::Uncertain,
                    'outcome_unknown' => true,
                    'raw_response' => PaymentLogSanitizer::sanitize(array_merge(
                        is_array($current->raw_response) ? $current->raw_response : [],
                        ['recovered_existing_prepared_attempt' => true],
                    )),
                ])->save();

                return ['attempt' => $current, 'recovered' => true];
            }, 3);
            $attempt = $resolution['attempt'];
            $recoveredPrepared = $resolution['recovered'];
        }
        if ($recoveredPrepared) {
            $this->auditUncertainAttempt(
                $attempt,
                'existing_prepared_attempt_not_dispatched',
                'A pre-existing prepared payout attempt was not dispatched again; the hold remains for reconciliation.',
            );
        }
        $this->schedulePoll($attempt);

        return $this->storedOutcome($attempt);
    }

    private function referenceConflictResponse(Withdrawal $withdrawal, string $reference, string $requestedFingerprint): GatewayWithdrawalResponse
    {
        $attempt = null;
        try {
            $attempt = DB::transaction(function () use ($reference): ?ThaiBankPayoutAttempt {
                $current = ThaiBankPayoutAttempt::query()
                    ->where('withdrawal_reference', $reference)
                    ->lockForUpdate()
                    ->first();
                if (! $current instanceof ThaiBankPayoutAttempt) {
                    return null;
                }
                if (! $current->state->isTerminal()) {
                    $raw = is_array($current->raw_response) ? $current->raw_response : [];
                    $raw['instruction_conflict'] = true;
                    $current->forceFill([
                        'state' => ThaiBankPayoutState::Uncertain,
                        'outcome_unknown' => true,
                        'raw_response' => PaymentLogSanitizer::sanitize($raw),
                    ])->save();
                }

                return $current;
            }, 3);
        } catch (Throwable $exception) {
            Log::critical('payment.thai_bank_payout_conflict_state_update_failed', PaymentLogSanitizer::sanitize([
                'withdrawal_id' => (int) $withdrawal->getKey(),
                'withdrawal_reference_hash' => hash('sha256', $reference),
                'exception_class' => $exception::class,
            ]));
            try {
                $attempt = ThaiBankPayoutAttempt::query()->where('withdrawal_reference', $reference)->first();
            } catch (Throwable) {
                $attempt = null;
            }
        }

        try {
            $this->auditLogService->log(
                userId: null,
                action: AuditAction::SecurityAlert,
                riskLevel: RiskLevel::High,
                auditable: $attempt,
                description: 'A payout reference was presented with a conflicting Thai bank instruction; the outcome remains on hold for reconciliation.',
                metadata: [
                    'bank' => $attempt?->bank,
                    'attempt_id' => $attempt?->getKey(),
                    'attempt_withdrawal_id' => $attempt?->withdrawal_id,
                    'requested_withdrawal_id' => (int) $withdrawal->getKey(),
                    'withdrawal_reference_hash' => hash('sha256', $reference),
                    'stored_fingerprint' => $attempt?->request_fingerprint,
                    'requested_fingerprint' => $requestedFingerprint,
                ],
            );
        } catch (Throwable $exception) {
            Log::critical('payment.thai_bank_payout_conflict_audit_failed', PaymentLogSanitizer::sanitize([
                'withdrawal_id' => (int) $withdrawal->getKey(),
                'withdrawal_reference_hash' => hash('sha256', $reference),
                'exception_class' => $exception::class,
            ]));
        }

        if ($attempt instanceof ThaiBankPayoutAttempt) {
            $this->schedulePoll($attempt);
        }

        return GatewayWithdrawalResponse::uncertain(
            providerReference: is_string($attempt?->provider_reference) ? $attempt->provider_reference : null,
            rawResponse: ['instruction_conflict' => true],
            metadata: [
                'gateway' => $this->name(),
                'reason' => 'thai_payout_reference_conflict',
                'attempt_state' => $attempt?->state?->value,
            ],
        );
    }

    /** @param array<string, mixed> $details */
    private function auditUncertainAttempt(ThaiBankPayoutAttempt $attempt, string $reason, string $description, array $details = []): void
    {
        $this->auditLogService->log(
            userId: null,
            action: AuditAction::Reconcile,
            riskLevel: RiskLevel::High,
            auditable: $attempt,
            description: $description,
            metadata: PaymentLogSanitizer::sanitize(array_merge([
                'attempt_id' => (int) $attempt->getKey(),
                'withdrawal_id' => (int) $attempt->withdrawal_id,
                'bank' => $attempt->bank,
                'state' => $attempt->state->value,
                'withdrawal_reference_hash' => hash('sha256', (string) $attempt->withdrawal_reference),
                'reason' => $reason,
            ], $details)),
        );
    }

    private function schedulePoll(ThaiBankPayoutAttempt $attempt): void
    {
        if (! in_array($attempt->state, [ThaiBankPayoutState::Accepted, ThaiBankPayoutState::Uncertain, ThaiBankPayoutState::Rejected], true)
            || strtolower((string) config('queue.default', 'database')) === 'sync') {
            return;
        }
        try {
            PollThaiBankPayoutStatusJob::dispatch((int) $attempt->getKey())
                ->delay(now()->addSeconds(max(1, (int) config('thai_payout.poll_after_seconds', 60))));
        } catch (Throwable $exception) {
            Log::warning('payment.thai_bank_payout_poll_schedule_failed', PaymentLogSanitizer::sanitize([
                'attempt_id' => (int) $attempt->getKey(),
                'exception_class' => $exception::class,
            ]));
        }
    }

    private function assertAmountLimits(string $amount): void
    {
        try {
            $minimum = Money::of((string) config('thai_payout.min_amount', ''), Currency::THB);
            $maximum = Money::of((string) config('thai_payout.max_amount', ''), Currency::THB);
            $dailyLimit = Money::of((string) config('thai_payout.daily_limit', ''), Currency::THB);
        } catch (Throwable $exception) {
            throw FinancialException::withCode('thai_payout_not_configured', 'Thai payout amount limits are not configured safely.', [], $exception);
        }
        if (! $minimum->isPositive() || ! $maximum->isPositive() || ! $dailyLimit->isPositive()
            || bccomp($maximum->toString(), $minimum->toString(), 2) < 0
            || bccomp($dailyLimit->toString(), $minimum->toString(), 2) < 0) {
            throw FinancialException::withCode('thai_payout_not_configured', 'Thai payout amount limits are not configured safely.');
        }
        if (bccomp($amount, $minimum->toString(), 2) < 0 || bccomp($amount, $maximum->toString(), 2) > 0) {
            throw FinancialException::withCode('thai_payout_amount_limit', 'The Thai bank payout amount is outside the configured transfer limits.');
        }
        $today = now()->startOfDay();
        $rows = ThaiBankPayoutAttempt::query()
            ->where('created_at', '>=', $today)
            ->whereIn('state', [ThaiBankPayoutState::Prepared->value, ThaiBankPayoutState::Accepted->value, ThaiBankPayoutState::Settled->value, ThaiBankPayoutState::Uncertain->value])
            ->get(['amount']);
        $total = '0.00';
        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->amount, 2);
        }
        if (bccomp(bcadd($total, $amount, 2), $dailyLimit->toString(), 2) > 0) {
            throw FinancialException::withCode('thai_payout_daily_limit', 'The Thai bank payout daily limit would be exceeded.');
        }
    }

    private function persistOutcome(ThaiBankPayoutAttempt $attempt, PayoutOutcome $outcome): ThaiBankPayoutAttempt
    {
        return DB::transaction(function () use ($attempt, $outcome): ThaiBankPayoutAttempt {
            $current = ThaiBankPayoutAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());
            if ($current->state->isTerminal()) {
                return $current;
            }
            $safe = PaymentLogSanitizer::sanitize($outcome->rawSanitised);
            $current->forceFill([
                'state' => $outcome->state,
                'provider_reference' => $outcome->providerReference ?? $current->provider_reference,
                'outcome_unknown' => $outcome->state === ThaiBankPayoutState::Uncertain,
                'raw_response' => $safe,
            ])->save();

            return $current;
        }, 3);
    }

    private function storedOutcome(ThaiBankPayoutAttempt $attempt): GatewayWithdrawalResponse
    {
        return match ($attempt->state) {
            ThaiBankPayoutState::Accepted => is_string($attempt->provider_reference) && $attempt->provider_reference !== ''
                ? GatewayWithdrawalResponse::pending(providerReference: $attempt->provider_reference, rawResponse: $attempt->raw_response ?? [], metadata: ['gateway' => $this->name(), 'state' => 'accepted'])
                : GatewayWithdrawalResponse::uncertain(providerReference: null, rawResponse: $attempt->raw_response ?? [], metadata: ['gateway' => $this->name(), 'state' => 'accepted_without_reference']),
            ThaiBankPayoutState::Settled => is_string($attempt->provider_reference) && $attempt->provider_reference !== ''
                ? GatewayWithdrawalResponse::completed(providerReference: $attempt->provider_reference, rawResponse: $attempt->raw_response ?? [], metadata: ['gateway' => $this->name(), 'state' => 'settled'])
                : GatewayWithdrawalResponse::uncertain(providerReference: null, rawResponse: $attempt->raw_response ?? [], metadata: ['gateway' => $this->name(), 'state' => 'settled_without_reference']),
            ThaiBankPayoutState::Rejected => GatewayWithdrawalResponse::failed(errorMessage: 'The bank confirmed the payout was rejected.', rawResponse: $attempt->raw_response ?? [], metadata: ['gateway' => $this->name(), 'state' => 'rejected']),
            default => GatewayWithdrawalResponse::uncertain(providerReference: $attempt->provider_reference, rawResponse: $attempt->raw_response ?? [], metadata: ['gateway' => $this->name(), 'state' => $attempt->state->value]),
        };
    }

    private function mapOutcome(PayoutOutcome $outcome): GatewayWithdrawalResponse
    {
        $raw = PaymentLogSanitizer::sanitize($outcome->rawSanitised);

        return match ($outcome->state) {
            ThaiBankPayoutState::Accepted => is_string($outcome->providerReference) && $outcome->providerReference !== ''
                ? GatewayWithdrawalResponse::pending(providerReference: $outcome->providerReference, rawResponse: $raw, metadata: ['gateway' => $this->name(), 'state' => 'accepted'])
                : GatewayWithdrawalResponse::uncertain(providerReference: null, rawResponse: $raw, metadata: ['gateway' => $this->name(), 'state' => 'accepted_without_reference']),
            ThaiBankPayoutState::Settled => is_string($outcome->providerReference) && $outcome->providerReference !== ''
                ? GatewayWithdrawalResponse::completed(providerReference: $outcome->providerReference, rawResponse: $raw, metadata: ['gateway' => $this->name(), 'state' => 'settled'])
                : GatewayWithdrawalResponse::uncertain(providerReference: null, rawResponse: $raw, metadata: ['gateway' => $this->name(), 'state' => 'settled_without_reference']),
            ThaiBankPayoutState::Rejected => GatewayWithdrawalResponse::failed(errorMessage: 'The bank confirmed the payout was rejected.', rawResponse: $raw, metadata: ['gateway' => $this->name(), 'state' => 'rejected']),
            default => GatewayWithdrawalResponse::uncertain(providerReference: $outcome->providerReference, rawResponse: $raw, metadata: ['gateway' => $this->name(), 'state' => $outcome->state->value]),
        };
    }

    private function adapterConfigured(): bool
    {
        return match (true) {
            $this->adapter instanceof ScbPayoutAdapter => $this->adapter->configured(),
            $this->adapter instanceof KbankPayoutAdapter => $this->adapter->configured(),
            default => false,
        };
    }

    private function adapterFor(string $bank): ThaiBankPayoutAdapterInterface
    {
        return match ($bank) {
            'scb' => app(ScbPayoutAdapter::class),
            'kbank' => app(KbankPayoutAdapter::class),
            default => throw FinancialException::withCode('thai_payout_bank_unsupported', 'The requested Thai bank is not supported.'),
        };
    }

    public static function instructionFingerprint(PayoutInstruction $instruction, string $bank): string
    {
        return hash('sha256', json_encode([
            $bank,
            $instruction->reference,
            $instruction->amount,
            $instruction->currency,
            $instruction->destinationType,
            $instruction->bankCode,
            $instruction->accountNumber,
            $instruction->accountName,
        ], JSON_THROW_ON_ERROR));
    }
}
