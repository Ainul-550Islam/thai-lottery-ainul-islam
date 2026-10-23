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
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Finance\FinancialStateTransitionService;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalIntegrityGuard;
use App\Services\Finance\WithdrawalReservationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrator for Real-Money Outbound Withdrawal Disbursements.
 *
 * NON-BLOCKING ARCHITECTURE:
 * Phase 1: DB Transaction (State Validation, Mark Processing, Commit Claim + Bound Payment)
 * Phase 2: External Gateway HTTP Request (NO DB locks held)
 * Phase 3: DB Transaction (Finalize Settlement, Release Hold on Definitive Failure, or Record Pending/Unknown)
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
    ) {}

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
        if (DB::transactionLevel() > 0) {
            throw FinancialException::withCode(
                'withdrawal_disbursement_transaction_active',
                'Outbound withdrawal disbursement cannot run inside an open database transaction.',
                ['withdrawal_id' => (int) $withdrawal->getKey()],
            );
        }

        // -------------------------------------------------------------
        // PHASE 1: DB Transaction - State Preparation & Claim Commit
        // -------------------------------------------------------------
        $preparation = DB::transaction(function () use ($withdrawal): array {
            $wallet = $this->locks->lock((int) $withdrawal->wallet_id);
            $current = $this->transitions->lockWithdrawal($withdrawal);
            $guard = app(WithdrawalIntegrityGuard::class);
            $accounts = app(WithdrawalProviderAccountService::class);

            $guard->assertTerms($current, $wallet);
            $accountScope = $accounts->assertActive($current);
            $canonicalProvider = $accounts->canonical($current->method->value);

            if ($current->provider !== null && $accounts->canonical($current->provider) !== $canonicalProvider) {
                $guard->refuse();
            }

            $payments = Payment::withTrashed()
                ->where('payable_type', Withdrawal::class)
                ->where('payable_id', $current->getKey())
                ->lockForUpdate()
                ->limit(2)
                ->get();

            if ($payments->count() > 1) {
                $guard->refuse();
            }

            $payment = $payments->first();
            if ($payment instanceof Payment) {
                if ($payment->trashed()
                    || (int) $payment->user_id !== (int) $current->user_id
                    || $payment->currency !== $current->currency
                    || $accounts->canonical($payment->method->value) !== $canonicalProvider
                    || $accounts->canonical((string) $payment->gateway) !== $canonicalProvider
                    || bccomp((string) $payment->amount, (string) $current->amount, 2) !== 0
                    || bccomp((string) $payment->fee, (string) $current->fee, 2) !== 0) {
                    $guard->refuse();
                }
            }

            if ($current->status === WithdrawalStatus::Completed) {
                $this->completionService->verifySettled($current);

                throw WithdrawalException::alreadyCompleted(
                    (int) $current->getKey(),
                    $current->financial_transaction_id,
                );
            }

            $metadata = is_array($current->metadata) ? $current->metadata : [];
            $existingClaim = $metadata[self::CLAIM] ?? null;

            if ($existingClaim !== null || $current->status === WithdrawalStatus::Processing) {
                app(WithdrawalReservationService::class)->assertActive($current, $wallet);

                if (is_array($existingClaim)) {
                    $fingerprint = $existingClaim['fingerprint'] ?? null;
                    if (! is_string($fingerprint) || ! hash_equals($fingerprint, $this->claimFingerprint($current, $accountScope))) {
                        $guard->refuse();
                    }

                    $claimState = is_string($existingClaim['state'] ?? null) ? $existingClaim['state'] : 'unknown';

                    return [
                        'withdrawal' => $current,
                        'payment' => $payment,
                        'driver' => null,
                        'already_in_flight' => true,
                        'claim_state' => $claimState,
                    ];
                }

                if ($existingClaim !== null) {
                    $guard->refuse();
                }

                return [
                    'withdrawal' => $current,
                    'payment' => $payment,
                    'driver' => null,
                    'already_in_flight' => true,
                    'claim_state' => 'legacy_unknown',
                ];
            }

            if ($current->status === WithdrawalStatus::Pending || $current->status === WithdrawalStatus::UnderReview) {
                if ((bool) config('finance.withdrawal.require_manual_approval', true)) {
                    throw WithdrawalException::notCompletable($current->id, $current->status);
                }

                $this->approvalService->approve($current);
                $current->refresh();
                $wallet->refresh();
            }

            if ($current->status !== WithdrawalStatus::Approved) {
                throw WithdrawalException::notCompletable($current->id, $current->status);
            }

            app(WithdrawalReservationService::class)->assertActive($current, $wallet);

            $driver = $this->gateways->withdrawalDriver($current->method);

            if (! $driver->supportsCurrency($current->currency)) {
                throw FinancialException::withCode(
                    'unsupported_gateway_currency',
                    sprintf('Gateway [%s] does not support currency [%s].', $driver->name(), $current->currency->value),
                    ['gateway' => $driver->name(), 'currency' => $current->currency->value],
                );
            }

            $current = $this->approvalService->markProcessing($current);

            if (! $payment instanceof Payment) {
                $payment = new Payment;
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

            $startedAt = Carbon::now()->toIso8601String();
            $dispatchKey = hash('sha256', json_encode([
                'withdrawal-dispatch',
                (int) $current->getKey(),
                $current->reference_number,
                $accountScope,
            ], JSON_THROW_ON_ERROR));

            $claim = [
                'version' => 1,
                'state' => 'sending',
                'key' => $dispatchKey,
                'account_scope' => $accountScope,
                'fingerprint' => $this->claimFingerprint($current, $accountScope),
                'payment_id' => (int) $payment->getKey(),
                'started_at' => $startedAt,
            ];

            $currentMetadata = is_array($current->metadata) ? $current->metadata : [];
            $currentMetadata['disbursement_attempt_started_at'] = $startedAt;
            $currentMetadata[self::CLAIM] = $claim;
            $current->metadata = $currentMetadata;
            $current->save();

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
                'dispatch_key' => $dispatchKey,
                'account_scope' => $accountScope,
            ];
        }, 3);

        /** @var Withdrawal $currentWithdrawal */
        $currentWithdrawal = $preparation['withdrawal'];

        if (($preparation['already_in_flight'] ?? false) === true) {
            $providerReference = $this->validProviderReference($currentWithdrawal->provider_reference);
            $claimState = (string) ($preparation['claim_state'] ?? 'unknown');

            if ($claimState === 'pending') {
                return GatewayWithdrawalResponse::pending(
                    providerReference: $providerReference ?? '',
                    metadata: [
                        'state' => 'already_in_flight',
                        'reconciliation_required' => true,
                    ],
                );
            }

            return GatewayWithdrawalResponse::uncertain(
                providerReference: $providerReference,
                metadata: [
                    'state' => $claimState === 'legacy_unknown' ? 'legacy_unknown' : 'already_in_flight',
                    'reconciliation_required' => true,
                ],
            );
        }

        /** @var Payment $payment */
        $payment = $preparation['payment'];
        /** @var \App\Services\Payment\Contracts\PaymentGatewayInterface $driver */
        $driver = $preparation['driver'];
        $dispatchKey = (string) $preparation['dispatch_key'];
        $accountScope = (string) $preparation['account_scope'];

        // -------------------------------------------------------------
        // PHASE 2: Gateway Call (NO DB Transaction / Locks held)
        // -------------------------------------------------------------
        $driverOptions = array_merge($options, [
            'idempotency_key' => $dispatchKey,
            'account_scope' => $accountScope,
        ]);

        try {
            $rawResponse = $driver->initiateWithdrawal($currentWithdrawal, $driverOptions);
        } catch (\Throwable $e) {
            Log::error('Outbound withdrawal outcome is unknown; reconciliation is required', [
                'withdrawal_id' => $currentWithdrawal->id,
                'gateway' => $driver->name(),
                'exception_class' => $e::class,
                'exception_code' => $e->getCode(),
            ]);

            $rawResponse = GatewayWithdrawalResponse::uncertain();
        }

        $validRef = $this->validProviderReference($rawResponse->providerReference);

        if ($rawResponse->outcomeUnknown) {
            $gatewayResponse = GatewayWithdrawalResponse::uncertain($validRef);
        } elseif ($rawResponse->isPending) {
            if ($rawResponse->successful && $validRef !== null) {
                $gatewayResponse = GatewayWithdrawalResponse::pending(
                    providerReference: $validRef,
                    metadata: [
                        'state' => 'provider_pending',
                        'reconciliation_required' => true,
                    ],
                );
            } else {
                $gatewayResponse = GatewayWithdrawalResponse::uncertain($validRef);
            }
        } elseif ($rawResponse->successful) {
            if ($validRef === null) {
                $gatewayResponse = GatewayWithdrawalResponse::uncertain();
            } else {
                $gatewayResponse = GatewayWithdrawalResponse::completed($validRef);
            }
        } else {
            $gatewayResponse = GatewayWithdrawalResponse::failed('The payout provider declined the disbursement.');
        }

        // -------------------------------------------------------------
        // PHASE 3: DB Transaction - Result Finalization
        // -------------------------------------------------------------
        return DB::transaction(function () use ($currentWithdrawal, $payment, $driver, $gatewayResponse): GatewayWithdrawalResponse {
            $this->locks->lock((int) $currentWithdrawal->wallet_id);
            $lockedWithdrawal = $this->transitions->lockWithdrawal($currentWithdrawal);
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($lockedWithdrawal->status === WithdrawalStatus::Completed) {
                $this->completionService->verifySettled($lockedWithdrawal);

                return GatewayWithdrawalResponse::completed((string) $lockedWithdrawal->provider_reference);
            }

            if ($gatewayResponse->successful && ! $gatewayResponse->isPending && ! $gatewayResponse->outcomeUnknown) {
                $providerRef = (string) $gatewayResponse->providerReference;

                app(WithdrawalProviderReferenceService::class)->bind(
                    $lockedWithdrawal,
                    $driver->name(),
                    $providerRef,
                    $lockedPayment,
                );

                $lockedPayment->status = PaymentStatus::Captured;
                $lockedPayment->captured_at = Carbon::now();
                $lockedPayment->gateway_reference = $providerRef;
                $lockedPayment->gateway_response = [
                    'gateway' => $driver->name(),
                    'outcome' => 'completed',
                    'provider_reference' => $providerRef,
                ];
                $lockedPayment->save();

                $metadata = is_array($lockedWithdrawal->metadata) ? $lockedWithdrawal->metadata : [];
                if (isset($metadata[self::CLAIM]) && is_array($metadata[self::CLAIM])) {
                    $metadata[self::CLAIM]['state'] = 'completed';
                    $metadata[self::CLAIM]['provider_reference'] = $providerRef;
                }
                $lockedWithdrawal->provider = $driver->name();
                $lockedWithdrawal->provider_reference = $providerRef;
                $lockedWithdrawal->metadata = $metadata;
                $lockedWithdrawal->save();

                $this->completionService->complete($lockedWithdrawal, null, [
                    'provider' => $driver->name(),
                    'provider_reference' => $providerRef,
                    'description' => sprintf('Withdrawal %s disbursed via %s', $lockedWithdrawal->reference_number, $driver->name()),
                ]);

                $this->recordAudit(
                    action: AuditAction::Withdraw,
                    auditable: $lockedWithdrawal,
                    description: sprintf('Withdrawal %s successfully disbursed and settled via %s.', $lockedWithdrawal->reference_number, $driver->name()),
                    metadata: [
                        'provider_reference' => $providerRef,
                        'gateway' => $driver->name(),
                    ],
                );
            } elseif ($gatewayResponse->isPending || $gatewayResponse->outcomeUnknown) {
                $providerRef = $this->validProviderReference($gatewayResponse->providerReference);
                $claimState = $gatewayResponse->outcomeUnknown ? 'unknown' : 'pending';
                $outcomeLabel = $gatewayResponse->outcomeUnknown ? 'outcome_unknown' : 'provider_pending';

                if ($providerRef !== null) {
                    app(WithdrawalProviderReferenceService::class)->bind(
                        $lockedWithdrawal,
                        $driver->name(),
                        $providerRef,
                        $lockedPayment,
                    );
                    $lockedPayment->gateway_reference = $providerRef;
                    $lockedWithdrawal->provider_reference = $providerRef;
                }

                $lockedPayment->gateway_response = [
                    'gateway' => $driver->name(),
                    'outcome' => 'pending',
                    'provider_reference' => $providerRef,
                    'state' => $outcomeLabel,
                    'reconciliation_required' => true,
                ];
                $lockedPayment->save();

                $lockedWithdrawal->provider = $driver->name();
                $metadata = is_array($lockedWithdrawal->metadata) ? $lockedWithdrawal->metadata : [];
                if (isset($metadata[self::CLAIM]) && is_array($metadata[self::CLAIM])) {
                    $metadata[self::CLAIM]['state'] = $claimState;
                    if ($providerRef !== null) {
                        $metadata[self::CLAIM]['provider_reference'] = $providerRef;
                    }
                }
                $metadata['disbursed_pending_at'] = Carbon::now()->toIso8601String();
                if ($providerRef !== null) {
                    $metadata['provider_reference'] = $providerRef;
                }
                $metadata['disbursement_outcome'] = $outcomeLabel;
                $metadata['reconciliation_required'] = true;
                $lockedWithdrawal->metadata = $metadata;
                $lockedWithdrawal->save();

                $this->recordAudit(
                    action: AuditAction::Withdraw,
                    auditable: $lockedWithdrawal,
                    description: sprintf('Withdrawal %s pending external provider confirmation from %s.', $lockedWithdrawal->reference_number, $driver->name()),
                    metadata: [
                        'provider_reference' => $providerRef,
                        'gateway' => $driver->name(),
                    ],
                );
            } else {
                $failureReason = 'The payout provider declined the disbursement.';

                $lockedPayment->status = PaymentStatus::Failed;
                $lockedPayment->failed_at = Carbon::now();
                $lockedPayment->failure_reason = $failureReason;
                $lockedPayment->gateway_response = [
                    'gateway' => $driver->name(),
                    'outcome' => 'failed',
                ];
                $lockedPayment->save();

                $this->approvalService->markProviderFailed($lockedWithdrawal);
                $lockedWithdrawal->refresh();

                $metadata = is_array($lockedWithdrawal->metadata) ? $lockedWithdrawal->metadata : [];
                if (isset($metadata[self::CLAIM]) && is_array($metadata[self::CLAIM])) {
                    $metadata[self::CLAIM]['state'] = 'failed';
                }
                $lockedWithdrawal->metadata = $metadata;
                $lockedWithdrawal->save();

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
        }, 3);
    }

    /**
     * Read-only validation of an existing dispatch claim under caller-owned SQL locks.
     *
     * @return array<string, mixed>
     */
    public function inspectReconciliationClaim(Withdrawal $row, Wallet $wallet, Payment $payment): array
    {
        if (DB::transactionLevel() < 1) {
            throw FinancialException::withCode(
                'withdrawal_reconciliation_transaction_required',
                'Claim inspection requires an active SQL transaction.',
            );
        }

        $guard = app(WithdrawalIntegrityGuard::class);
        $accounts = app(WithdrawalProviderAccountService::class);

        $guard->assertTerms($row, $wallet);
        $scope = $accounts->assertActive($row);
        if (in_array($row->status, [WithdrawalStatus::Approved, WithdrawalStatus::Processing], true)) {
            app(WithdrawalReservationService::class)->assertActive($row, $wallet);
        }

        $provider = $accounts->canonical($row->method->value);
        if ($payment->trashed()
            || $payment->payable_type !== Withdrawal::class
            || (int) $payment->payable_id !== (int) $row->id
            || (int) $payment->user_id !== (int) $row->user_id
            || $payment->currency !== $row->currency
            || $accounts->canonical($payment->method->value) !== $provider
            || $accounts->canonical((string) $payment->gateway) !== $provider
            || bccomp((string) $payment->amount, (string) $row->amount, 2) !== 0
            || bccomp((string) $payment->fee, (string) $row->fee, 2) !== 0) {
            $guard->refuse();
        }

        $metadata = is_array($row->metadata) ? $row->metadata : [];
        $claim = $metadata[self::CLAIM] ?? null;

        if (! is_array($claim)
            || ! is_string($claim['state'] ?? null)
            || ! is_string($claim['fingerprint'] ?? null)
            || ! hash_equals($claim['fingerprint'], $this->claimFingerprint($row, $scope))
            || (isset($claim['payment_id']) && (int) $claim['payment_id'] !== (int) $payment->id)) {
            $guard->refuse();
        }

        return $claim;
    }

    private function claimFingerprint(Withdrawal $row, string $accountScope): string
    {
        return hash('sha256', json_encode([
            (int) $row->getKey(),
            $row->reference_number,
            (int) $row->user_id,
            (int) $row->wallet_id,
            $row->currency->value,
            (string) $row->amount,
            (string) $row->fee,
            (string) $row->net_amount,
            $row->method->value,
            $row->payout_details,
            $accountScope,
        ], JSON_THROW_ON_ERROR));
    }

    private function validProviderReference(?string $reference): ?string
    {
        if (! is_string($reference)) {
            return null;
        }

        if ($reference === '' || trim($reference) !== $reference || strlen($reference) > 255 || preg_match('/[\x00-\x1f\x7f]/', $reference) === 1) {
            return null;
        }

        return $reference;
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
}
