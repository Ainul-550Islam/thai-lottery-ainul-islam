<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\Enums\AuditAction;
use App\Enums\PaymentStatus;
use App\Enums\RiskLevel;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\WithdrawalException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Withdrawal;
use App\Services\Finance\FinancialStateTransitionService;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrator for Real-Money Outbound Withdrawal Disbursements.
 *
 * NON-BLOCKING ARCHITECTURE:
 * Phase 1: DB Transaction (State Validation, Mark Processing, Commit)
 * Phase 2: External Gateway HTTP Request (NO DB locks held)
 * Phase 3: DB Transaction (Finalize Settlement, Release Hold on Failure, or Record Pending)
 */
class WithdrawalDisbursementService
{
    public const CLAIM = 'outbound_dispatch';

    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly WalletLockService $locks,
        private readonly WalletHoldService $holds,
        private readonly FinancialStateTransitionService $transitions,
        private readonly WithdrawalApprovalService $approvalService,
        private readonly WithdrawalCompletionService $completionService,
    ) {
    }

    /**
     * Read-only inspection of the durable dispatch claim inside an active transaction.
     *
     * @return array<string, mixed>
     */
    public function inspectReconciliationClaim(\App\Models\Withdrawal $row, \App\Models\Wallet $wallet, Payment $payment): array
    {
        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            throw FinancialException::withCode(
                'reconciliation_transaction_required',
                'Reconciliation claim inspection requires an active SQL transaction.',
            );
        }

        app(\App\Services\Finance\WithdrawalIntegrityGuard::class)->assertTerms($row, $wallet);
        $scope = app(WithdrawalProviderAccountService::class)->assertActive($row);

        $metadata = is_array($row->metadata) ? $row->metadata : [];
        $claim = $metadata[self::CLAIM] ?? null;

        if (! is_array($claim)) {
            $startedAt = $metadata['disbursement_attempt_started_at'] ?? $metadata['processing_started_at'] ?? null;
            if (! is_string($startedAt) || $startedAt === '') {
                throw FinancialException::withCode('missing_disbursement_claim', 'No disbursement claim exists for withdrawal.');
            }

            return [
                'state' => (string) ($metadata['disbursement_outcome'] ?? 'legacy_unknown'),
                'started_at' => $startedAt,
                'account_scope' => $scope,
                'provider_reference' => $row->provider_reference,
            ];
        }

        return $claim;
    }

    /**
     * Disburse an approved withdrawal to an external payment provider.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws WithdrawalException
     * @throws FinancialException
     */
    public function disburse(Withdrawal $withdrawal, array $options = []): GatewayWithdrawalResponse
    {
        if (DB::transactionLevel() > 0 || DB::connection()->getPdo()->inTransaction()) {
            throw FinancialException::withCode(
                'disbursement_requires_top_level',
                'Outbound withdrawal disbursement must own its top-level transaction boundaries.',
                ['withdrawal_id' => (int) $withdrawal->getKey(), 'transaction_level' => DB::transactionLevel()],
            );
        }

        // -------------------------------------------------------------
        // PHASE 1: DB Transaction - State Preparation & Mark Processing
        // -------------------------------------------------------------
        $preparation = DB::transaction(function () use ($withdrawal): array {
            $wallet = $this->locks->lock((int) $withdrawal->wallet_id);
            $current = $this->transitions->lockWithdrawal($withdrawal);
            $startedProcessingNow = false;

            if ($current->status === WithdrawalStatus::Completed) {
                throw WithdrawalException::alreadyCompleted(
                    (int) $current->getKey(),
                    $current->financial_transaction_id,
                );
            }

            if ($current->status === WithdrawalStatus::Pending || $current->status === WithdrawalStatus::UnderReview) {
                // If manual approval is required, disallow direct dispatch
                if ((bool) config('finance.withdrawal.require_manual_approval', true)) {
                    throw WithdrawalException::notCompletable($current->id, $current->status);
                }

                // Auto-approve if permitted
                $this->approvalService->approve($current);
                $current->refresh();
            }

            if ($current->status === WithdrawalStatus::Approved) {
                $current = $this->approvalService->markProcessing($current);
                $startedProcessingNow = true;
            }

            if ($current->status !== WithdrawalStatus::Processing) {
                throw WithdrawalException::notCompletable($current->id, $current->status);
            }

            // Processing is a one-shot external side-effect boundary. A retry
            // that sees an already-processing row cannot know whether the
            // provider received the prior request, so it must not transmit a
            // second payout; the operator reconciles this pending obligation.
            if (! $startedProcessingNow) {
                return [
                    'withdrawal' => $current,
                    'payment' => null,
                    'driver' => null,
                    'already_in_flight' => true,
                ];
            }

            $scope = app(WithdrawalProviderAccountService::class)->assertActive($current);
            $startedAt = Carbon::now()->toIso8601String();
            $currentMetadata = is_array($current->metadata) ? $current->metadata : [];
            $currentMetadata['disbursement_attempt_started_at'] = $startedAt;
            $currentMetadata[self::CLAIM] = [
                'state' => 'sending',
                'started_at' => $startedAt,
                'account_scope' => $scope,
                'provider_reference' => $current->provider_reference,
            ];
            $current->metadata = $currentMetadata;
            $current->save();

            // FINAL AUDIT #5: withdrawal payout resolves through the
            // fail-closed guard - a disabled or non-withdrawal-capable
            // gateway is refused here; the manual-approval design above is
            // untouched.
            $driver = $this->gateways->withdrawalDriver($current->method);

            if (! $driver->supportsCurrency($current->currency)) {
                throw FinancialException::withCode(
                    'unsupported_gateway_currency',
                    sprintf('Gateway [%s] does not support currency [%s].', $driver->name(), $current->currency->value),
                    ['gateway' => $driver->name(), 'currency' => $current->currency->value],
                );
            }

            // Create or resolve Payment aggregate
            $payment = Payment::query()
                ->where('payable_type', Withdrawal::class)
                ->where('payable_id', $current->getKey())
                ->first();

            if (! $payment instanceof Payment) {
                $payment = new Payment();
                $payment->fill([
                    'reference_number' => 'PAY-WD-'.strtoupper(bin2hex(random_bytes(8))),
                    'user_id' => (int) $current->user_id,
                    'payable_type' => Withdrawal::class,
                    'payable_id' => $current->getKey(),
                    'method' => $current->method,
                    'status' => PaymentStatus::Pending,
                    'currency' => $current->currency,
                    'amount' => (string) $current->amount,
                    'fee' => (string) $current->fee,
                    'gateway' => $driver->name(),
                    'metadata' => [
                        'withdrawal_reference' => $current->reference_number,
                        'net_amount' => (string) $current->net_amount,
                    ],
                ]);
                $payment->save();
            }

            $this->recordAudit(
                action: AuditAction::Withdraw,
                auditable: $current,
                description: sprintf('Withdrawal %s prepared for a one-time disbursement attempt via %s.', $current->reference_number, $driver->name()),
                metadata: [
                    'withdrawal_id' => $current->getKey(),
                    'amount' => (string) $current->amount,
                    'net_amount' => (string) $current->net_amount,
                    'gateway' => $driver->name(),
                    'attempt_started' => true,
                ],
            );

            return [
                'withdrawal' => $current,
                'payment' => $payment,
                'driver' => $driver,
            ];
        });

        /** @var Withdrawal $currentWithdrawal */
        $currentWithdrawal = $preparation['withdrawal'];

        if (($preparation['already_in_flight'] ?? false) === true) {
            $providerReference = trim((string) $currentWithdrawal->provider_reference);

            return new GatewayWithdrawalResponse(
                successful: true,
                isPending: true,
                providerReference: $providerReference !== '' ? $providerReference : null,
                metadata: [
                    'state' => 'already_in_flight',
                    'reconciliation_required' => true,
                ],
            );
        }

        /** @var Payment $payment */
        $payment = $preparation['payment'];
        /** @var \App\Services\Payment\Contracts\PaymentGatewayInterface $driver */
        $driver = $preparation['driver'];

        // -------------------------------------------------------------
        // PHASE 2: Gateway Call (NO DB Transaction / Locks held)
        // -------------------------------------------------------------
        $outcomeUnknown = false;

        try {
            $gatewayResponse = $driver->initiateWithdrawal($currentWithdrawal, $options);
        } catch (\Throwable $e) {
            $outcomeUnknown = true;
            Log::error('Outbound withdrawal outcome is unknown; reconciliation is required', [
                'withdrawal_id' => $currentWithdrawal->id,
                'gateway' => $driver->name(),
                'exception_class' => $e::class,
                'exception_code' => $e->getCode(),
            ]);

            // A timeout or connection loss is ambiguous: the provider may have
            // accepted the payout before the response vanished. Keep the wallet
            // hold and the withdrawal pending; never release funds or retry an
            // unkeyed transfer based on an unknown outcome.
            $gatewayResponse = GatewayWithdrawalResponse::uncertain(metadata: [
                'state' => 'outcome_unknown',
                'reconciliation_required' => true,
            ]);
        }

        $manualMetadata = [];
        foreach (['manual_disbursement_required', 'requires_manual_disbursement', 'requires_b2c_disbursement', 'manual_bank_wire'] as $flag) {
            if (($gatewayResponse->metadata[$flag] ?? false) === true) {
                $manualMetadata[$flag] = true;
            }
        }
        $manualDisbursement = $gatewayResponse->successful && $gatewayResponse->isPending && $manualMetadata !== [];
        if ($manualDisbursement) {
            $manualMetadata['manual_disbursement_required'] = true;
        }

        // Do not persist or return provider-controlled raw bodies, credentials,
        // payer details, or free-form error strings. Only retain the fields
        // required to settle or reconcile the payout.
        if ($gatewayResponse->successful && ! $gatewayResponse->isPending) {
            $trimmedRef = is_string($gatewayResponse->providerReference) ? trim($gatewayResponse->providerReference) : '';
            if ($trimmedRef === '' || strlen($trimmedRef) > 255 || preg_match('/[\x00-\x1f\x7f]/', $trimmedRef) === 1) {
                $gatewayResponse = GatewayWithdrawalResponse::uncertain(metadata: [
                    'state' => 'outcome_unknown',
                    'reconciliation_required' => true,
                ]);
            }
        }

        if ($gatewayResponse->isPending) {
            $gatewayResponse = new GatewayWithdrawalResponse(
                successful: $gatewayResponse->successful,
                isPending: true,
                providerReference: $gatewayResponse->providerReference,
                errorMessage: $gatewayResponse->errorMessage,
                outcomeUnknown: $outcomeUnknown || $gatewayResponse->outcomeUnknown,
                metadata: array_merge($manualMetadata, [
                    'state' => $outcomeUnknown ? 'outcome_unknown' : ($manualDisbursement ? 'reconciliation_required' : ((string) ($gatewayResponse->metadata['state'] ?? 'provider_pending'))),
                    'reconciliation_required' => true,
                ]),
            );
        } elseif ($gatewayResponse->successful) {
            $gatewayResponse = new GatewayWithdrawalResponse(
                successful: true,
                providerReference: $gatewayResponse->providerReference,
            );
        } else {
            $gatewayResponse = GatewayWithdrawalResponse::failed('The payout provider declined the disbursement.');
        }

        // -------------------------------------------------------------
        // PHASE 3: DB Transaction - Result Finalization
        // -------------------------------------------------------------
        return DB::transaction(function () use ($currentWithdrawal, $payment, $driver, $gatewayResponse): GatewayWithdrawalResponse {
            $this->locks->lock((int) $currentWithdrawal->wallet_id);
            $lockedWithdrawal = $this->transitions->lockWithdrawal($currentWithdrawal);

            // Re-check state
            if ($lockedWithdrawal->status === WithdrawalStatus::Completed) {
                return $gatewayResponse;
            }

            if ($gatewayResponse->successful && ! $gatewayResponse->isPending) {
                // Immediate synchronous payout completion
                $payment->status = PaymentStatus::Captured;
                $payment->captured_at = Carbon::now();
                $payment->gateway_reference = $gatewayResponse->providerReference;
                $payment->gateway_response = [
                    'gateway' => $driver->name(),
                    'outcome' => 'completed',
                    'provider_reference' => $gatewayResponse->providerReference,
                ];
                $payment->save();

                $this->completionService->complete($lockedWithdrawal, null, [
                    'provider' => $driver->name(),
                    'provider_reference' => $gatewayResponse->providerReference,
                    'description' => sprintf('Withdrawal %s disbursed via %s', $lockedWithdrawal->reference_number, $driver->name()),
                ]);

                $this->recordAudit(
                    action: AuditAction::Withdraw,
                    auditable: $lockedWithdrawal,
                    description: sprintf('Withdrawal %s successfully disbursed and settled via %s.', $lockedWithdrawal->reference_number, $driver->name()),
                    metadata: [
                        'provider_reference' => $gatewayResponse->providerReference,
                        'gateway' => $driver->name(),
                    ],
                );
            } elseif ($gatewayResponse->isPending) {
                // Asynchronous pending disbursement (awaits webhook / payout completion)
                if (is_string($gatewayResponse->providerReference) && trim($gatewayResponse->providerReference) !== '') {
                    app(WithdrawalProviderReferenceService::class)->bind(
                        $lockedWithdrawal,
                        $driver->name(),
                        trim($gatewayResponse->providerReference),
                        $payment,
                    );
                }

                $payment->gateway_reference = $gatewayResponse->providerReference;
                $payment->gateway_response = array_merge([
                    'gateway' => $driver->name(),
                    'outcome' => 'pending',
                    'provider_reference' => $gatewayResponse->providerReference,
                    'state' => (string) ($gatewayResponse->metadata['state'] ?? 'provider_pending'),
                    'reconciliation_required' => (bool) ($gatewayResponse->metadata['reconciliation_required'] ?? false),
                ], array_intersect_key($gatewayResponse->metadata, array_flip([
                    'manual_disbursement_required', 'requires_manual_disbursement', 'requires_b2c_disbursement', 'manual_bank_wire',
                ])));
                $payment->save();

                $lockedWithdrawal->provider = $driver->name();
                $lockedWithdrawal->provider_reference = $gatewayResponse->providerReference;
                $metadata = is_array($lockedWithdrawal->metadata) ? $lockedWithdrawal->metadata : [];
                $pendingState = (string) ($gatewayResponse->metadata['state'] ?? 'provider_pending');
                $manualPending = ($gatewayResponse->metadata['manual_disbursement_required'] ?? false) === true
                    || ($gatewayResponse->metadata['requires_manual_disbursement'] ?? false) === true
                    || ($gatewayResponse->metadata['requires_b2c_disbursement'] ?? false) === true
                    || ($gatewayResponse->metadata['manual_bank_wire'] ?? false) === true;
                $claimState = in_array($pendingState, ['outcome_unknown', 'unknown'], true) ? 'unknown' : ($manualPending ? 'reconciliation_required' : 'pending');
                if (isset($metadata[self::CLAIM]) && is_array($metadata[self::CLAIM])) {
                    $metadata[self::CLAIM]['state'] = $claimState;
                    $metadata[self::CLAIM]['provider_reference'] = $gatewayResponse->providerReference;
                }
                $metadata['disbursed_pending_at'] = Carbon::now()->toIso8601String();
                $metadata['provider_reference'] = $gatewayResponse->providerReference;
                $metadata['disbursement_outcome'] = $pendingState;
                $metadata['reconciliation_required'] = (bool) ($gatewayResponse->metadata['reconciliation_required'] ?? false);
                foreach (['manual_disbursement_required', 'requires_manual_disbursement', 'requires_b2c_disbursement', 'manual_bank_wire'] as $flag) {
                    if (($gatewayResponse->metadata[$flag] ?? false) === true) {
                        $metadata[$flag] = true;
                    }
                }
                if ($manualPending) {
                    $metadata['manual_disbursement_required'] = true;
                    $metadata['operator_payout_queue'] = 'withdrawal_reconciliation';
                }
                $lockedWithdrawal->metadata = $metadata;
                $lockedWithdrawal->save();

                $this->recordAudit(
                    action: AuditAction::Withdraw,
                    auditable: $lockedWithdrawal,
                    description: $manualPending
                        ? sprintf('Withdrawal %s queued for evidence-gated manual disbursement via %s.', $lockedWithdrawal->reference_number, $driver->name())
                        : sprintf('Withdrawal %s pending external provider confirmation from %s.', $lockedWithdrawal->reference_number, $driver->name()),
                    metadata: array_merge([
                        'provider_reference' => $gatewayResponse->providerReference,
                        'gateway' => $driver->name(),
                    ], array_intersect_key($gatewayResponse->metadata, array_flip([
                        'manual_disbursement_required', 'requires_manual_disbursement', 'requires_b2c_disbursement', 'manual_bank_wire',
                    ]))),
                );
            } else {
                // Only a definitive provider decline reaches this branch. The
                // response has already been reduced to a generic, non-sensitive
                // result; transport errors are retained as pending for review.
                $failureReason = 'The payout provider declined the disbursement.';

                $payment->status = PaymentStatus::Failed;
                $payment->failed_at = Carbon::now();
                $payment->failure_reason = $failureReason;
                $payment->gateway_response = [
                    'gateway' => $driver->name(),
                    'outcome' => 'failed',
                ];
                $payment->save();

                $this->approvalService->markProviderFailed($lockedWithdrawal);

                $this->recordAudit(
                    action: AuditAction::Withdraw,
                    auditable: $lockedWithdrawal,
                    description: sprintf('Withdrawal %s disbursement was declined by %s.', $lockedWithdrawal->reference_number, $driver->name()),
                    oldValues: ['status' => 'processing'],
                    newValues: ['status' => 'failed'],
                    metadata: [
                        'gateway' => $driver->name(),
                        'reason_code' => 'provider_declined',
                    ],
                );
            }

            return $gatewayResponse;
        });
    }

    private function recordAudit(
        AuditAction $action,
        object $auditable,
        string $description,
        array $oldValues = [],
        array $newValues = [],
        array $metadata = [],
    ): void {
        $log = new AuditLog();
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
}
