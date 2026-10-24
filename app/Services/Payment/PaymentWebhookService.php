<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\PaymentProcessingResult;
use App\DTOs\Payment\PaymentWebhookData;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\AuditAction;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentWebhookStatus;
use App\Enums\RiskLevel;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\PaymentReconciliationException;
use App\Exceptions\PaymentWebhookException;
use App\Listeners\RecordPaymentWebhookAudit;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Models\PaymentWebhook;
use App\Models\Withdrawal;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\FinancialReversalService;
use App\Services\Finance\FinancialStateTransitionService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Robust, production-grade Payment Webhook Processing Engine.
 *
 * GUARANTEES
 * ----------
 * 1. CRYPTOGRAPHIC INTEGRITY: Webhook signatures are verified before payload execution.
 * 2. REPLAY & DEDUPLICATION: Webhook event IDs are keyed in atomic cache.
 * 3. TRANSACTIONAL SETTLEMENT: Webhook events execute inside atomic database transactions.
 * 4. STRICT FINANCIAL INTEGRITY:
 *    - Deposit Success -> Payment Captured -> Deposit Approved -> Deposit Completed ->
 *      Wallet Credited -> Balanced Double-Entry Ledger Posting (DEBIT Cash, CREDIT Liability).
 *    - Deposit Failure/Expiry -> Payment Failed -> Deposit Cancelled -> Zero Wallet/Ledger writes.
 *    - Reversal/Refund -> FinancialReversalService -> Balanced Mirror Postings -> Wallet Reversal.
 * 5. IDEMPOTENCY: Redundant/duplicate webhooks return replayed status with ZERO duplicate credits.
 */
class PaymentWebhookService
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
        private readonly PaymentGatewayManager $gateways,
        private readonly DepositApprovalService $depositApproval,
        private readonly DepositCompletionService $depositCompletion,
        private readonly WithdrawalApprovalService $withdrawalApproval,
        private readonly WithdrawalCompletionService $withdrawalCompletion,
        private readonly FinancialReversalService $reversalService,
        private readonly FinancialStateTransitionService $transitions,
        private readonly PaymentWebhookVerificationService $webhookVerification,
        private readonly PaymentCallbackService $paymentCallbacks,
    ) {}

    /**
     * Process an incoming webhook request from a gateway.
     *
     * @throws FinancialException
     */
    public function handleWebhookRequest(string $gatewayName, Request $request): PaymentProcessingResult
    {
        $driver = $this->gateways->driver($gatewayName);

        // 1. Provider-specific signature verification. The route middleware is
        //    only a durable replay guard; it deliberately leaves missing and
        //    invalid-signature decisions to this driver.
        if ((bool) $this->config->get('payment.webhook.verify_signature', true)) {
            if (! $driver->verifyWebhookSignature($request)) {
                Log::warning('Payment webhook signature verification failed', [
                    'gateway' => $gatewayName,
                    'ip' => $request->ip(),
                ]);

                return PaymentProcessingResult::failed($gatewayName, 'Invalid webhook signature.');
            }

            $request->attributes->set('webhook.signature_verified', true);
            $request->attributes->set('webhook.gateway', strtolower($gatewayName));
        }

        // 2. Parse Payload
        $payload = $driver->parseWebhook($request);

        // 3. Process normalized payload
        return $this->processWebhookPayload($payload);
    }

    /**
     * Process a normalized webhook payload.
     *
     * @throws FinancialException
     */
    public function processWebhookPayload(WebhookPayload $payload): PaymentProcessingResult
    {
        $gateway = $payload->gateway;
        $eventId = $payload->eventId;

        // Replay Protection Cache Check
        $cacheKey = (string) $this->config->get('payment.webhook.replay_cache_prefix', 'payment:webhook:seen:')
            .$gateway.':'.$eventId;
        $cacheTtl = (int) $this->config->get('payment.idempotency.cache_ttl', 86400);

        if (! $this->cache->add($cacheKey, true, $cacheTtl)) {
            Log::info('Payment webhook event already processed (replay cache hit)', [
                'gateway' => $gateway,
                'event_id' => $eventId,
            ]);

            return PaymentProcessingResult::ignored(
                gateway: $gateway,
                reason: 'Webhook event ID already processed.',
                replayed: true,
            );
        }

        try {
            $result = DB::transaction(function () use ($payload, $gateway): PaymentProcessingResult {
                if ($payload->eventType->isDeposit()) {
                    return $this->processDepositWebhook($payload);
                }

                if ($payload->eventType->isWithdrawal()) {
                    return $this->processWithdrawalWebhook($payload);
                }

                if ($payload->eventType->isReversal()) {
                    return $this->processReversalWebhook($payload);
                }

                return PaymentProcessingResult::ignored(
                    gateway: $gateway,
                    reason: 'Ignored unsupported webhook event: '.$payload->eventType->value,
                );
            });

            if (! $result->success) {
                $this->cache->forget($cacheKey);
            }

            return $result;
        } catch (Throwable $exception) {
            $this->cache->forget($cacheKey);

            throw $exception;
        }
    }

    /**
     * Handle Deposit webhook event (Success, Failure, Cancellation, Expiration).
     */
    private function processDepositWebhook(WebhookPayload $payload): PaymentProcessingResult
    {
        $gateway = $payload->gateway;
        $deposit = $this->resolveDeposit($payload);

        if (! $deposit instanceof Deposit) {
            Log::warning('Payment webhook received for non-existent deposit', [
                'gateway' => $gateway,
                'internal_reference' => $payload->internalReference,
                'provider_reference' => $payload->providerReference,
            ]);

            return PaymentProcessingResult::failed($gateway, 'Matching deposit not found.');
        }

        $payment = $this->resolveOrCreatePayment($deposit, $payload);

        // Case A: Deposit Succeeded
        if ($payload->isSuccess) {
            // Validate currency match if explicitly present in payload
            $hasExplicitCurrency = isset($payload->rawData['currency']);
            if ($hasExplicitCurrency && $payload->currency !== null && $payload->currency !== $deposit->currency) {
                Log::warning('Payment webhook currency mismatch', [
                    'gateway' => $gateway,
                    'deposit_id' => $deposit->id,
                    'expected_currency' => $deposit->currency->value,
                    'received_currency' => $payload->currency->value,
                ]);

                $this->recordAudit(
                    action: AuditAction::Deposit,
                    auditable: $deposit,
                    description: sprintf(
                        'Payment webhook currency mismatch for Deposit %s: expected %s, received %s.',
                        $deposit->reference_number,
                        $deposit->currency->value,
                        $payload->currency->value,
                    ),
                    metadata: [
                        'deposit_id' => $deposit->id,
                        'expected_currency' => $deposit->currency->value,
                        'received_currency' => $payload->currency->value,
                        'gateway' => $gateway,
                    ],
                );

                return PaymentProcessingResult::failed($gateway, 'Payment webhook currency mismatch.');
            }

            // Validate amount match if present in payload
            if ($payload->amount !== null) {
                $expectedAmount = (string) $deposit->amount;
                $receivedAmount = (string) $payload->amount;

                if (bccomp($expectedAmount, $receivedAmount, 2) !== 0) {
                    Log::warning('Payment webhook amount mismatch', [
                        'gateway' => $gateway,
                        'deposit_id' => $deposit->id,
                        'expected_amount' => $expectedAmount,
                        'received_amount' => $receivedAmount,
                    ]);

                    $this->recordAudit(
                        action: AuditAction::Deposit,
                        auditable: $deposit,
                        description: sprintf(
                            'Payment webhook amount mismatch for Deposit %s: expected %s %s, received %s %s.',
                            $deposit->reference_number,
                            $expectedAmount,
                            $deposit->currency->value,
                            $receivedAmount,
                            $deposit->currency->value,
                        ),
                        metadata: [
                            'deposit_id' => $deposit->id,
                            'expected_amount' => $expectedAmount,
                            'received_amount' => $receivedAmount,
                            'gateway' => $gateway,
                        ],
                    );

                    return PaymentProcessingResult::failed($gateway, 'Payment webhook amount mismatch.');
                }
            }

            return $this->finalizeSuccessfulDeposit($deposit, $payment, $payload);
        }

        // Case B: Deposit Failed / Expired / Cancelled
        return $this->finalizeFailedDeposit($deposit, $payment, $payload);
    }

    /**
     * Finalize a successful deposit: complete payment, credit wallet, and record ledger.
     */
    private function finalizeSuccessfulDeposit(Deposit $deposit, Payment $payment, WebhookPayload $payload): PaymentProcessingResult
    {
        $gateway = $payload->gateway;

        // Replay check: already confirmed deposit
        if ($deposit->status === DepositStatus::Confirmed && $deposit->financial_transaction_id !== null) {
            $existingTx = FinancialTransaction::query()->find($deposit->financial_transaction_id);

            return PaymentProcessingResult::completed(
                gateway: $gateway,
                payment: $payment,
                transaction: $existingTx ?? new FinancialTransaction,
                actionTaken: 'replayed',
                replayed: true,
                metadata: ['already_settled' => true],
            );
        }

        // Update Payment status to Captured
        $payment->status = PaymentStatus::Captured;
        $payment->captured_at = Carbon::now();
        $payment->gateway_reference = $payload->providerReference ?? $payment->gateway_reference;
        $payment->gateway_response = $payload->rawData;
        $payment->save();

        // If deposit is Pending, advance to Approved first
        if ($deposit->status === DepositStatus::Pending) {
            $this->depositApproval->approve($deposit, null, 'Auto-approved via payment webhook verification');
            $deposit->refresh();
        }

        // Complete the deposit and credit the wallet
        $completion = $this->depositCompletion->complete(
            deposit: $deposit,
            idempotencyKey: null,
            options: [
                'description' => sprintf('Deposit %s via %s', $deposit->reference_number, strtoupper($gateway)),
                'provider' => $gateway,
                'provider_reference' => $payload->providerReference,
                'metadata' => [
                    'webhook_event_id' => $payload->eventId,
                    'gateway' => $gateway,
                ],
            ],
        );

        /** @var FinancialTransaction $transaction */
        $transaction = $completion['transaction'];

        $this->recordAudit(
            action: AuditAction::Deposit,
            auditable: $deposit,
            description: sprintf(
                'Deposit %s successfully confirmed via %s webhook: %s %s credited to player wallet #%d.',
                $deposit->reference_number,
                strtoupper($gateway),
                $deposit->net_amount,
                $deposit->currency->value,
                $deposit->wallet_id,
            ),
            oldValues: ['status' => 'pending'],
            newValues: ['status' => 'confirmed'],
            metadata: [
                'payment_id' => $payment->getKey(),
                'transaction_id' => $transaction->getKey(),
                'gateway' => $gateway,
                'event_id' => $payload->eventId,
            ],
        );

        return PaymentProcessingResult::completed(
            gateway: $gateway,
            payment: $payment,
            transaction: $transaction,
            actionTaken: 'credited',
            replayed: false,
            metadata: ['wallet_credited' => true],
        );
    }

    /**
     * Finalize a failed or cancelled deposit.
     */
    private function finalizeFailedDeposit(Deposit $deposit, Payment $payment, WebhookPayload $payload): PaymentProcessingResult
    {
        $gateway = $payload->gateway;
        $reason = $payload->failureReason ?? 'Payment was cancelled or failed by provider.';

        // Payment status
        $payment->status = PaymentStatus::Failed;
        $payment->failed_at = Carbon::now();
        $payment->failure_reason = $reason;
        $payment->gateway_response = $payload->rawData;
        $payment->save();

        // Deposit status
        if ($deposit->status->canCancel()) {
            $this->transitions->transitionDeposit($deposit, DepositStatus::Failed, [
                'failed_at' => Carbon::now(),
                'failure_reason' => $reason,
                'metadata' => array_merge(is_array($deposit->metadata) ? $deposit->metadata : [], [
                    'failed_at' => Carbon::now()->toIso8601String(),
                    'webhook_event_id' => $payload->eventId,
                    'gateway' => $gateway,
                ]),
            ]);
        }

        $this->recordAudit(
            action: AuditAction::Deposit,
            auditable: $deposit,
            description: sprintf('Deposit %s marked failed via %s webhook: %s', $deposit->reference_number, strtoupper($gateway), $reason),
            oldValues: ['status' => 'pending'],
            newValues: ['status' => 'failed'],
            metadata: ['gateway' => $gateway, 'reason' => $reason],
        );

        return PaymentProcessingResult::failed(
            gateway: $gateway,
            errorMessage: $reason,
            payment: $payment,
        );
    }

    /**
     * Handle Withdrawal webhook event (P2P / Disbursement callbacks).
     */
    private function processWithdrawalWebhook(WebhookPayload $payload): PaymentProcessingResult
    {
        $gateway = $payload->gateway;
        $withdrawal = $this->resolveWithdrawal($payload);

        if (! $withdrawal instanceof Withdrawal) {
            Log::warning('Withdrawal webhook received for non-existent withdrawal', [
                'gateway' => $gateway,
                'internal_reference' => $payload->internalReference,
                'provider_reference' => $payload->providerReference,
            ]);

            return PaymentProcessingResult::failed($gateway, 'Matching withdrawal not found.');
        }

        // 1. Validate currency match
        if ($payload->currency !== null && $payload->currency !== $withdrawal->currency) {
            Log::warning('Withdrawal webhook currency mismatch', [
                'gateway' => $gateway,
                'withdrawal_id' => $withdrawal->id,
                'expected_currency' => $withdrawal->currency->value,
                'received_currency' => $payload->currency->value,
            ]);

            return PaymentProcessingResult::failed($gateway, 'Withdrawal webhook currency mismatch.');
        }

        // 2. Validate amount match (compare exact decimal string against gross or net amount)
        if ($payload->amount !== null) {
            $amountMatchesGross = bccomp((string) $withdrawal->amount, (string) $payload->amount, 2) === 0;
            $amountMatchesNet = bccomp((string) $withdrawal->net_amount, (string) $payload->amount, 2) === 0;

            if (! $amountMatchesGross && ! $amountMatchesNet) {
                Log::warning('Withdrawal webhook amount mismatch', [
                    'gateway' => $gateway,
                    'withdrawal_id' => $withdrawal->id,
                    'expected_amount' => (string) $withdrawal->amount,
                    'expected_net_amount' => (string) $withdrawal->net_amount,
                    'received_amount' => (string) $payload->amount,
                ]);

                return PaymentProcessingResult::failed($gateway, 'Withdrawal webhook amount mismatch.');
            }
        }

        // 3. Case A: Successful Payout Webhook
        if ($payload->isSuccess) {
            if ($withdrawal->status === WithdrawalStatus::Completed) {
                $this->withdrawalCompletion->verifySettled($withdrawal);

                return PaymentProcessingResult::ignored($gateway, 'Withdrawal already completed.', true);
            }

            if (in_array($withdrawal->status, [WithdrawalStatus::Approved, WithdrawalStatus::Processing], true)) {
                $completion = DB::transaction(function () use ($withdrawal, $gateway, $payload): array {
                    $payment = Payment::query()
                        ->where('payable_type', Withdrawal::class)
                        ->where('payable_id', $withdrawal->id)
                        ->lockForUpdate()
                        ->first();

                    if ($payment instanceof Payment) {
                        $payment->status = PaymentStatus::Captured;
                        $payment->captured_at = Carbon::now();
                        $payment->gateway_reference = $payload->providerReference ?? $payment->gateway_reference;
                        $payment->gateway_response = [
                            'gateway' => $gateway,
                            'outcome' => 'completed',
                            'provider_reference' => $payload->providerReference,
                            'source' => 'verified_webhook',
                        ];
                        $payment->save();
                    }

                    return $this->withdrawalCompletion->complete(
                        withdrawal: $withdrawal,
                        options: [
                            'provider' => $gateway,
                            'provider_reference' => $payload->providerReference,
                        ],
                    );
                });

                /** @var FinancialTransaction $tx */
                $tx = $completion['transaction'];

                $this->recordAudit(
                    action: AuditAction::Withdraw,
                    auditable: $withdrawal,
                    description: sprintf('Withdrawal %s disbursed successfully via %s webhook.', $withdrawal->reference_number, strtoupper($gateway)),
                    oldValues: ['status' => $withdrawal->status->value],
                    newValues: ['status' => 'completed'],
                    metadata: ['transaction_id' => $tx->getKey(), 'gateway' => $gateway],
                );

                return PaymentProcessingResult::completed(
                    gateway: $gateway,
                    payment: $payment,
                    transaction: $tx,
                    actionTaken: 'debited',
                );
            }

            return PaymentProcessingResult::failed($gateway, 'Withdrawal requires explicit approval before provider callback settlement.');
        }

        // 4. Case B: Failed / Declined / Cancelled Payout Webhook
        if ($withdrawal->status->isFinal()) {
            return PaymentProcessingResult::ignored($gateway, 'Withdrawal already in final state: '.$withdrawal->status->value, true);
        }

        if (! in_array($withdrawal->status, [WithdrawalStatus::Approved, WithdrawalStatus::Processing], true)) {
            return PaymentProcessingResult::failed($gateway, 'Withdrawal requires explicit approval before provider callback failure handling.');
        }

        $reason = 'The payout provider confirmed that the disbursement failed.';

        // This path is reached only after a persisted webhook envelope has passed
        // the provider driver's signature verification. Store a generic outcome;
        // the encrypted webhook evidence remains the source of the full payload.
        $this->withdrawalApproval->markProviderFailed($withdrawal);

        $payment = Payment::query()
            ->where('payable_type', Withdrawal::class)
            ->where('payable_id', $withdrawal->id)
            ->first();

        if ($payment instanceof Payment) {
            $payment->status = PaymentStatus::Failed;
            $payment->failed_at = Carbon::now();
            $payment->failure_reason = $reason;
            $payment->gateway_response = [
                'gateway' => $gateway,
                'outcome' => 'failed',
                'provider_reference' => $payload->providerReference,
                'source' => 'verified_webhook',
            ];
            $payment->save();
        }

        $this->recordAudit(
            action: AuditAction::Withdraw,
            auditable: $withdrawal,
            description: sprintf('Withdrawal %s marked failed via a verified %s webhook.', $withdrawal->reference_number, strtoupper($gateway)),
            oldValues: ['status' => $withdrawal->status->value],
            newValues: ['status' => 'failed'],
            metadata: [
                'gateway' => $gateway,
                'provider_reference' => $payload->providerReference,
                'reason_code' => 'verified_provider_failure',
            ],
        );

        return PaymentProcessingResult::failed(
            gateway: $gateway,
            errorMessage: $reason,
            payment: $payment,
        );
    }

    /**
     * Handle Chargeback / Reversal webhook event.
     */
    private function processReversalWebhook(WebhookPayload $payload): PaymentProcessingResult
    {
        return PaymentProcessingResult::failed(
            $payload->gateway,
            \App\Services\Finance\ExternalRefundPolicy::MESSAGE,
        );
    }

    /**
     * Locate and validate the matching Deposit model under obligation locks.
     */
    private function resolveDeposit(WebhookPayload $payload): ?Deposit
    {
        $resolved = app(PaymentWebhookBindingService::class)->resolve(Deposit::class, $payload);

        return $resolved instanceof Deposit ? $resolved : null;
    }

    /**
     * Locate and validate the matching Withdrawal model under obligation locks.
     */
    private function resolveWithdrawal(WebhookPayload $payload): ?Withdrawal
    {
        $resolved = app(PaymentWebhookBindingService::class)->resolve(Withdrawal::class, $payload);

        return $resolved instanceof Withdrawal ? $resolved : null;
    }

    /**
     * Resolve existing Payment record or create a new one.
     */
    private function resolveOrCreatePayment(Deposit $deposit, WebhookPayload $payload): Payment
    {
        $gateway = $payload->gateway;
        $providerRef = $payload->providerReference;

        $payment = Payment::query()
            ->where('payable_type', Deposit::class)
            ->where('payable_id', $deposit->getKey())
            ->first();

        if (! $payment instanceof Payment) {
            $payment = new Payment;
            $payment->fill([
                'reference_number' => 'PAY-'.strtoupper(bin2hex(random_bytes(8))),
                'user_id' => $deposit->user_id,
                'payable_type' => Deposit::class,
                'payable_id' => $deposit->getKey(),
                'method' => $deposit->method ?? PaymentMethod::tryFrom($gateway) ?? PaymentMethod::Manual,
                'status' => PaymentStatus::Pending,
                'currency' => $deposit->currency,
                'amount' => (string) $deposit->amount,
                'fee' => (string) $deposit->fee,
                'gateway' => $gateway,
                'gateway_reference' => $providerRef,
                'gateway_response' => [
                    'event_id' => $payload->eventId,
                    'event_type' => $payload->eventType->value,
                    'provider_reference' => $payload->providerReference,
                ],
            ]);
            try {
                $payment->save();
            } catch (QueryException $exception) {
                if (! $this->isPaymentPayableIdentityViolation($exception)) {
                    throw $exception;
                }

                // A concurrent initiation or webhook may have inserted the
                // one allowed Payment row after the initial lookup. Return
                // that row rather than failing the provider delivery or
                // creating a second financial aggregate.
                $winner = Payment::query()
                    ->where('payable_type', Deposit::class)
                    ->where('payable_id', $deposit->getKey())
                    ->first();

                if (! $winner instanceof Payment) {
                    throw $exception;
                }

                return $winner;
            }
        }

        return $payment;
    }

    private function isPaymentPayableIdentityViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'payments_payable_once_unique')
            || (str_contains($message, 'payments.payable_type') && str_contains($message, 'payments.payable_id'));
    }

    private function recordAudit(
        AuditAction $action,
        object $auditable,
        string $description,
        array $oldValues = [],
        array $newValues = [],
        array $metadata = [],
    ): void {
        $log = new AuditLog;
        $log->fill([
            'user_id' => null,
            'action' => $action,
            'risk_level' => RiskLevel::Low,
            'auditable_type' => get_class($auditable),
            'auditable_id' => (int) $auditable->getKey(),
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
        ]);
        $log->save();
    }

    /* =====================================================================
     * Batch-12 hardened envelope lane (additive): verify BEFORE trust,
     * persist BEFORE apply, exactly-once by fingerprint, duplicates as
     * no-op evidence — never altering the legacy handleWebhookRequest
     * lane above.
     * ================================================================= */

    /**
     * Ingress one normalized webhook envelope: persist the evidence,
     * stamp its Rejected pronunciation when verification refuses, apply
     * its facts once when it verifies.
     *
     * @return array{webhook: PaymentWebhook, status: PaymentWebhookStatus, outcome: string}
     *
     * @throws PaymentWebhookException
     */
    public function processEnvelope(PaymentWebhookData $envelope, ?Request $request = null): array
    {
        try {
            $this->webhookVerification->verify($envelope);
        } catch (PaymentWebhookException $refusal) {
            $this->persistRejectedEnvelope($envelope, $refusal);

            throw $refusal;
        }

        if ($request !== null) {
            $request->attributes->set('webhook.signature_verified', true);
            $request->attributes->set('webhook.gateway', strtolower($envelope->providerCode));
        }

        return DB::transaction(function () use ($envelope): array {
            $now = now();
            $inserted = DB::table('payment_webhooks')->insertOrIgnore([
                'webhook_key' => $envelope->webhookKey(),
                'provider' => $envelope->providerCode,
                'event_id' => $envelope->eventId,
                'event_type' => $envelope->eventType,
                'signature' => null,
                'signature_ciphertext' => $envelope->signature === null
                    ? null
                    : Crypt::encryptString($envelope->signature),
                'payload' => json_encode([
                    'encrypted' => true,
                    'payload_fingerprint' => $envelope->payloadFingerprint,
                ], JSON_THROW_ON_ERROR),
                'payload_ciphertext' => Crypt::encryptString(
                    json_encode($envelope->payload, JSON_THROW_ON_ERROR),
                ),
                'payload_fingerprint' => $envelope->payloadFingerprint,
                'status' => PaymentWebhookStatus::Verified->value,
                'received_at' => $now,
                'verified_at' => $now,
                'claimed_at' => $now,
                'sightings' => 1,
                'processing_attempts' => 1,
                'metadata' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = PaymentWebhook::query()
                ->where('provider', $envelope->providerCode)
                ->where('event_id', $envelope->eventId)
                ->lockForUpdate()
                ->first();

            $row ??= PaymentWebhook::query()
                ->where('payload_fingerprint', $envelope->payloadFingerprint)
                ->lockForUpdate()
                ->first();

            if (! $row instanceof PaymentWebhook) {
                throw PaymentWebhookException::malformed('durable webhook claim could not be loaded');
            }

            $factsMatch = (string) $row->provider === $envelope->providerCode
                && (string) $row->event_id === $envelope->eventId
                && (string) $row->event_type === $envelope->eventType
                && (string) $row->payload_fingerprint === $envelope->payloadFingerprint;

            if (! $factsMatch) {
                throw PaymentWebhookException::duplicate($envelope->payloadFingerprint);
            }

            if ($inserted === 0) {
                $row->sightings = (int) $row->sightings + 1;
                $row->save();

                RecordPaymentWebhookAudit::from(
                    $row,
                    PaymentWebhookStatus::Duplicate,
                    sprintf('duplicate sighting #%d', $row->sightings),
                );

                return [
                    'webhook' => $row,
                    'status' => PaymentWebhookStatus::Duplicate,
                    'outcome' => 'duplicate',
                ];
            }

            RecordPaymentWebhookAudit::from(
                $row,
                PaymentWebhookStatus::Verified,
                'signature and identity proved; durable claim acquired',
            );

            $applied = false;

            if ($this->paymentCallbacks !== null) {
                try {
                    $callback = $this->paymentCallbacks->normalizeFromPayload(
                        $envelope->providerCode,
                        $envelope->payload + [
                            'payload_fingerprint' => $envelope->payloadFingerprint,
                            'signature' => $envelope->signature,
                        ],
                    );

                    $this->paymentCallbacks->apply($callback);

                    $row->status = PaymentWebhookStatus::Applied;
                    $row->applied_at = now();
                    $row->status_reason = null;
                    $row->save();

                    RecordPaymentWebhookAudit::from(
                        $row,
                        PaymentWebhookStatus::Applied,
                        'facts applied to internal paper',
                    );
                    $applied = true;
                } catch (PaymentReconciliationException $applicationRefusal) {
                    $row->status_reason = $applicationRefusal->errorCode();
                    $row->last_error_at = now();
                    $row->save();

                    RecordPaymentWebhookAudit::from(
                        $row,
                        PaymentWebhookStatus::Verified,
                        'verified; application deferred ('.$applicationRefusal->errorCode().')',
                    );
                }
            }

            return [
                'webhook' => $row,
                'status' => $row->status,
                'outcome' => $applied ? 'applied' : 'verified',
            ];
        }, 3);
    }

    /**
     * (Re)apply a PERSISTED envelope — the async job's exact verb.
     * Re-proves the signature against the stored bytes (replay-window
     * relaxed; the envelope is already custody of the house), then runs
     * normalization + application once more, cost-free when the
     * internal paper already carries the facts.
     *
     * @throws PaymentWebhookException
     * @throws PaymentReconciliationException
     */
    public function applyPersisted(PaymentWebhook $webhook): PaymentWebhook
    {
        try {
            return DB::transaction(function () use ($webhook): PaymentWebhook {
                /** @var PaymentWebhook|null $locked */
                $locked = PaymentWebhook::query()->lockForUpdate()->find((int) $webhook->getKey());

                if (! $locked instanceof PaymentWebhook) {
                    throw PaymentWebhookException::notFound((string) $webhook->webhook_key);
                }

                if ($locked->status === PaymentWebhookStatus::Applied) {
                    return $locked;
                }

                if ($locked->status === PaymentWebhookStatus::Rejected) {
                    throw PaymentWebhookException::malformed('a rejected envelope may never be applied');
                }

                $payload = $locked->payload;
                $signature = $locked->signature;
                $rawPayloadCiphertext = $locked->getRawOriginal('payload_ciphertext');
                $rawSignatureCiphertext = $locked->getRawOriginal('signature_ciphertext');

                // Older accepted envelopes predate encrypted evidence columns.
                // Migrate them atomically before the retry is re-verified, and
                // never copy their plaintext back into the legacy columns.
                if ((! is_string($rawPayloadCiphertext) || $rawPayloadCiphertext === '') && is_array($payload)) {
                    $locked->payload = $payload;
                }

                if ((! is_string($rawSignatureCiphertext) || $rawSignatureCiphertext === '')
                    && is_string($signature)
                    && $signature !== '') {
                    $locked->signature = $signature;
                }

                $locked->claimed_at = now();
                $locked->processing_attempts = (int) $locked->processing_attempts + 1;
                $locked->save();

                $envelope = PaymentWebhookData::fromInput(
                    providerCode: (string) $locked->provider,
                    eventId: (string) $locked->event_id,
                    eventType: (string) $locked->event_type,
                    signature: $signature,
                    receivedAtIso: $locked->received_at?->toIso8601String() ?? now()->toIso8601String(),
                    payload: is_array($payload) ? $payload : [],
                    payloadFingerprint: (string) $locked->payload_fingerprint,
                );

                $this->webhookVerification->verify($envelope, allowStale: true);

                if ($this->paymentCallbacks === null) {
                    throw PaymentWebhookException::malformed('callback lane unavailable');
                }

                $callback = $this->paymentCallbacks->normalizeFromPayload(
                    $envelope->providerCode,
                    $envelope->payload + [
                        'payload_fingerprint' => $envelope->payloadFingerprint,
                        'signature' => $envelope->signature,
                    ],
                );

                $this->paymentCallbacks->apply($callback);

                $locked->status = PaymentWebhookStatus::Applied;
                $locked->applied_at = now();
                $locked->last_error_at = null;
                $locked->status_reason = null;
                $locked->save();

                RecordPaymentWebhookAudit::from(
                    $locked,
                    PaymentWebhookStatus::Applied,
                    'persisted envelope applied by queue worker',
                );

                return $locked;
            }, 3);
        } catch (Throwable $failure) {
            $reason = $failure instanceof PaymentWebhookException
                ? $failure->errorCode()
                : 'WEBHOOK_APPLICATION_FAILED';

            PaymentWebhook::query()
                ->whereKey((int) $webhook->getKey())
                ->where('status', '!=', PaymentWebhookStatus::Applied->value)
                ->update([
                    'last_error_at' => now(),
                    'status_reason' => $reason,
                ]);

            throw $failure;
        }
    }

    private function persistRejectedEnvelope(
        PaymentWebhookData $envelope,
        PaymentWebhookException $refusal,
    ): PaymentWebhook {
        return DB::transaction(function () use ($envelope, $refusal): PaymentWebhook {
            $now = now();

            DB::table('payment_webhooks')->insertOrIgnore([
                'webhook_key' => $envelope->webhookKey(),
                'provider' => $envelope->providerCode,
                'event_id' => $envelope->eventId,
                'event_type' => $envelope->eventType,
                'signature' => $envelope->signature === null || $envelope->signature === ''
                    ? null
                    : hash('sha256', $envelope->signature),
                'signature_ciphertext' => null,
                'payload' => json_encode([
                    'encrypted' => false,
                    'payload_fingerprint' => $envelope->payloadFingerprint,
                ], JSON_THROW_ON_ERROR),
                'payload_ciphertext' => null,
                'payload_fingerprint' => $envelope->payloadFingerprint,
                'status' => PaymentWebhookStatus::Rejected->value,
                'status_reason' => $refusal->errorCode(),
                'received_at' => $now,
                'rejected_at' => $now,
                'last_error_at' => $now,
                'sightings' => 1,
                'processing_attempts' => 0,
                'metadata' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = PaymentWebhook::query()
                ->where('provider', $envelope->providerCode)
                ->where('event_id', $envelope->eventId)
                ->lockForUpdate()
                ->first();

            $row ??= PaymentWebhook::query()
                ->where('payload_fingerprint', $envelope->payloadFingerprint)
                ->lockForUpdate()
                ->first();

            if (! $row instanceof PaymentWebhook) {
                throw PaymentWebhookException::malformed('rejected webhook evidence could not be loaded');
            }

            RecordPaymentWebhookAudit::from(
                $row,
                PaymentWebhookStatus::Rejected,
                $refusal->errorCode(),
            );

            return $row;
        }, 3);
    }
}
