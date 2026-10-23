<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\WithdrawalApprovalStatus;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\WithdrawalException;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Payment\WithdrawalDisbursementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The review decision on a withdrawal, and the reservation of the funds.
 */
final class WithdrawalApprovalService
{
    public function __construct(
        private readonly WalletLockService $locks,
        private readonly WalletHoldService $holds,
        private readonly FinancialStateTransitionService $transitions,
        private readonly \App\Services\Withdrawal\WithdrawalKycGateService $kycGate,
    ) {}

    /**
     * Approve a withdrawal and reserve its amount.
     *
     * @return array{withdrawal: Withdrawal, wallet: Wallet, reserved: string, snapshot: array{balance: string, available_balance: string, locked_balance: string, currency: string, hold_type: string|null}}
     *
     * @throws WithdrawalException
     * @throws InsufficientBalanceException
     * @throws FinancialException
     */
    public function approve(Withdrawal $withdrawal, ?int $reviewerUserId = null, ?string $note = null): array
    {
        $current = $withdrawal->fresh() ?? $withdrawal;

        if ($current instanceof Withdrawal && $this->kycGate->requiresGate($current)) {
            $this->kycGate->gate($current);
            $withdrawal = $current;
        }

        return $this->withinTransaction(function () use ($withdrawal, $reviewerUserId, $note): array {
            $wallet = $this->locks->lock((int) $withdrawal->wallet_id);
            $current = $this->transitions->lockWithdrawal($withdrawal);

            $this->assertWalletMatches($current, $wallet);
            $this->assertNotAlreadySettled($current);

            if ($current->status->holdsReservedFunds()) {
                throw WithdrawalException::alreadyReserved($current->id, $current->status);
            }

            if (! $current->status->canApprove()) {
                throw WithdrawalException::notApprovable($current->id, $current->status);
            }

            $this->assertCurrencyMatches($current, $wallet);

            if (! $wallet->canDebit()) {
                throw WithdrawalException::walletNotDebitable((int) $wallet->getKey(), $wallet->status->value);
            }

            $amount = $this->amountOf($current);

            $heldWallet = app(WithdrawalReservationService::class)->reserve($current, $wallet);

            $now = Carbon::now();

            $approved = $this->transitions->transitionWithdrawal($current, WithdrawalStatus::Approved, array_filter([
                'reviewed_by' => $reviewerUserId,
                'reviewed_at' => $now,
                'approved_at' => $now,
                'metadata' => $this->mergeMetadata($current, array_filter([
                    'approval_note' => $note,
                    'reserved_amount' => $amount->toString(),
                    'reserved_at' => $now->toIso8601String(),
                ], static fn ($value): bool => $value !== null)),
            ], static fn ($value): bool => $value !== null));

            $this->holds->assertInvariant($heldWallet);

            return [
                'withdrawal' => $approved,
                'wallet' => $heldWallet,
                'reserved' => $amount->toString(),
                'snapshot' => $this->holds->snapshot($heldWallet),
            ];
        });
    }

    /**
     * Refuse the withdrawal and give any reservation back.
     *
     * @return array{withdrawal: Withdrawal, wallet: Wallet, hold_released: bool, snapshot: array{balance: string, available_balance: string, locked_balance: string, currency: string, hold_type: string|null}}
     *
     * @throws WithdrawalException
     * @throws FinancialException
     */
    public function reject(Withdrawal $withdrawal, string $reason, ?int $reviewerUserId = null): array
    {
        return $this->withinTransaction(function () use ($withdrawal, $reason, $reviewerUserId): array {
            $wallet = $this->locks->lock((int) $withdrawal->wallet_id);
            $current = $this->transitions->lockWithdrawal($withdrawal);

            $this->assertWalletMatches($current, $wallet);
            $this->assertNotAlreadySettled($current);

            if (! $current->status->canReject()) {
                throw WithdrawalException::notRejectable($current->id, $current->status);
            }

            $guard = app(WithdrawalIntegrityGuard::class);
            $guard->assertTerms($current, $wallet);

            if ((is_array($current->metadata) ? ($current->metadata[WithdrawalDisbursementService::CLAIM] ?? null) : null) !== null) {
                $guard->refuse();
            }

            $released = false;
            $workingWallet = $wallet;

            $reservations = app(WithdrawalReservationService::class);
            $initialHolds = app(WithdrawalInitialHoldService::class);

            if ($current->status->holdsReservedFunds() || $reservations->exists($current)) {
                $outcome = $reservations->release($current, $wallet, false);
                $workingWallet = $outcome['wallet'];
                $released = $outcome['released'];
            } else {
                $initialHolds->assertRequiredExists($current, $wallet);

                if ($initialHolds->exists($current)) {
                    $outcome = $initialHolds->releasePending($current, $wallet);
                    $workingWallet = $outcome['wallet'];
                    $released = $outcome['released'];
                }
            }

            $now = Carbon::now();

            $rejected = $this->transitions->transitionWithdrawal($current, WithdrawalStatus::Rejected, array_filter([
                'reviewed_by' => $reviewerUserId,
                'reviewed_at' => $now,
                'rejected_at' => $now,
                'rejection_reason' => $reason,
                'metadata' => $this->mergeMetadata($current, [
                    'hold_released' => $released,
                    'rejected_at' => $now->toIso8601String(),
                ]),
            ], static fn ($value): bool => $value !== null));

            $this->holds->assertInvariant($workingWallet);

            return [
                'withdrawal' => $rejected,
                'wallet' => $workingWallet,
                'hold_released' => $released,
                'snapshot' => $this->holds->snapshot($workingWallet),
            ];
        });
    }

    /**
     * Move an approved withdrawal into `processing`.
     *
     * @throws WithdrawalException
     * @throws FinancialException
     */
    public function markProcessing(Withdrawal $withdrawal): Withdrawal
    {
        $preCheck = $withdrawal->fresh() ?? $withdrawal;

        if ($preCheck instanceof Withdrawal && $this->kycGate->requiresGate($preCheck)) {
            $job = new \App\Jobs\VerifyWithdrawalKycJob((int) $preCheck->getKey());
            $verdict = $job->handle($this->kycGate);

            if (($verdict['detention'] ?? false) === true) {
                throw WithdrawalException::notCompletable(
                    (int) $preCheck->getKey(),
                    WithdrawalStatus::KycRequired,
                );
            }
        }

        return $this->withinTransaction(function () use ($withdrawal): Withdrawal {
            $wallet = $this->locks->lock((int) $withdrawal->wallet_id);
            $current = $this->transitions->lockWithdrawal($withdrawal);

            $this->assertWalletMatches($current, $wallet);
            $this->assertNotAlreadySettled($current);

            if ($current->status !== WithdrawalStatus::Approved) {
                throw WithdrawalException::notCompletable($current->id, $current->status);
            }

            $this->assertReservationIntact($current, $wallet);
            app(WithdrawalReservationService::class)->assertActive($current, $wallet);

            return $this->transitions->transitionWithdrawal($current, WithdrawalStatus::Processing, [
                'metadata' => $this->mergeMetadata($current, [
                    'processing_started_at' => Carbon::now()->toIso8601String(),
                ]),
            ]);
        });
    }

    /**
     * Record a pre-disbursement failure (or verified final failure) and release the reservation.
     *
     * @return array{withdrawal: Withdrawal, wallet: Wallet, hold_released: bool}
     *
     * @throws WithdrawalException
     * @throws FinancialException
     */
    public function markFailed(Withdrawal $withdrawal, string $reason, bool $verifiedFinalFailure = false): array
    {
        return $this->failWithdrawal($withdrawal, $reason, [], $verifiedFinalFailure);
    }

    /**
     * Finalize a definitive failure reported by a provider response or verified webhook.
     *
     * @return array{withdrawal: Withdrawal, wallet: Wallet, hold_released: bool}
     *
     * @throws WithdrawalException
     * @throws FinancialException
     */
    public function markProviderFailed(Withdrawal $withdrawal): array
    {
        return $this->failWithdrawal(
            $withdrawal,
            'The payout provider confirmed that the disbursement failed.',
            [
                'reconciliation_required' => false,
                'disbursement_outcome' => 'provider_declined',
                'failure_source' => 'provider',
            ],
            true,
        );
    }

    /**
     * Finalize a not-paid outcome after a second operator records provider evidence.
     *
     * @return array{withdrawal: Withdrawal, wallet: Wallet, hold_released: bool}
     *
     * @throws WithdrawalException
     * @throws FinancialException
     */
    public function markReconciledFailed(
        Withdrawal $withdrawal,
        int $operatorUserId,
        string $providerEvidenceReference,
    ): array {
        $evidenceReference = trim($providerEvidenceReference);

        if ($operatorUserId < 1
            || strlen($evidenceReference) > 191
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/D', $evidenceReference) !== 1) {
            throw FinancialException::withCode(
                'withdrawal_reconciliation_evidence_invalid',
                'A valid operator and provider confirmation reference are required to reconcile a payout.',
                ['withdrawal_id' => (int) $withdrawal->getKey()],
            );
        }

        return $this->failWithdrawal(
            $withdrawal,
            'The payout provider confirmed that no payout was made.',
            [
                'reconciliation_required' => false,
                'disbursement_outcome' => 'confirmed_not_paid',
                'failure_source' => 'operator_reconciliation',
                'provider_confirmation_reference' => $evidenceReference,
                'reconciled_by' => $operatorUserId,
                'reconciled_at' => Carbon::now()->toIso8601String(),
            ],
            true,
            $operatorUserId,
        );
    }

    /**
     * @param  array<string, mixed>  $additionalMetadata
     * @return array{withdrawal: Withdrawal, wallet: Wallet, hold_released: bool}
     */
    private function failWithdrawal(
        Withdrawal $withdrawal,
        string $reason,
        array $additionalMetadata,
        bool $providerOutcomeConfirmed,
        ?int $reconciledBy = null,
    ): array {
        return $this->withinTransaction(function () use (
            $withdrawal,
            $reason,
            $additionalMetadata,
            $providerOutcomeConfirmed,
            $reconciledBy,
        ): array {
            $wallet = $this->locks->lock((int) $withdrawal->wallet_id);
            $current = $this->transitions->lockWithdrawal($withdrawal);

            $this->assertWalletMatches($current, $wallet);
            $this->assertNotAlreadySettled($current);

            if ($current->status === WithdrawalStatus::Processing && ! $providerOutcomeConfirmed) {
                throw FinancialException::withCode(
                    'withdrawal_reconciliation_required',
                    'A payout with a possibly delivered provider request must be reconciled with provider evidence before its hold can be released.',
                    ['withdrawal_id' => (int) $current->getKey()],
                );
            }

            if ($reconciledBy !== null) {
                if ($current->status !== WithdrawalStatus::Processing) {
                    throw WithdrawalException::notRejectable($current->id, $current->status);
                }

                if ($current->reviewed_by !== null && (int) $current->reviewed_by === $reconciledBy) {
                    throw FinancialException::withCode(
                        'withdrawal_reconciliation_separation_of_duties',
                        'A different operator must reconcile a payout than the operator who approved it.',
                        ['withdrawal_id' => (int) $current->getKey()],
                    );
                }
            }

            if (! $current->status->canTransitionTo(WithdrawalStatus::Failed)) {
                throw WithdrawalException::notRejectable($current->id, $current->status);
            }

            $guard = app(WithdrawalIntegrityGuard::class);
            $guard->assertTerms($current, $wallet);

            if ((is_array($current->metadata) ? ($current->metadata[WithdrawalDisbursementService::CLAIM] ?? null) : null) !== null && ! $providerOutcomeConfirmed) {
                $guard->refuse();
            }

            $released = false;
            $workingWallet = $wallet;

            $reservations = app(WithdrawalReservationService::class);
            $initialHolds = app(WithdrawalInitialHoldService::class);

            if ($current->status->holdsReservedFunds() || $reservations->exists($current)) {
                $outcome = $reservations->release($current, $wallet, $providerOutcomeConfirmed);
                $workingWallet = $outcome['wallet'];
                $released = $outcome['released'];
            } else {
                $initialHolds->assertRequiredExists($current, $wallet);

                if ($initialHolds->exists($current)) {
                    $outcome = $initialHolds->releasePending($current, $wallet);
                    $workingWallet = $outcome['wallet'];
                    $released = $outcome['released'];
                }
            }

            $metadata = array_merge($additionalMetadata, [
                'failed_at' => Carbon::now()->toIso8601String(),
                'hold_released' => $released,
            ]);

            $failed = $this->transitions->transitionWithdrawal($current, WithdrawalStatus::Failed, [
                'rejection_reason' => $reason,
                'metadata' => $this->mergeMetadata($current, $metadata),
            ]);

            $this->holds->assertInvariant($workingWallet);

            return [
                'withdrawal' => $failed,
                'wallet' => $workingWallet,
                'hold_released' => $released,
            ];
        });
    }

    /**
     * The derived review outcome. Never read from a column, because there is none.
     */
    public function approvalStatus(Withdrawal $withdrawal): WithdrawalApprovalStatus
    {
        return WithdrawalApprovalStatus::fromWithdrawal($withdrawal);
    }

    /**
     * Whether the request may be approved right now, without throwing.
     */
    public function canApprove(Withdrawal $withdrawal): bool
    {
        return $withdrawal->status->canApprove()
            && ! $withdrawal->status->holdsReservedFunds()
            && $withdrawal->status !== WithdrawalStatus::Completed
            && $withdrawal->financial_transaction_id === null;
    }

    /**
     * Whether the request may be rejected right now, without throwing.
     */
    public function canReject(Withdrawal $withdrawal): bool
    {
        return $withdrawal->status->canReject()
            && $withdrawal->status !== WithdrawalStatus::Completed
            && $withdrawal->financial_transaction_id === null;
    }

    public function amountOf(Withdrawal $withdrawal): Money
    {
        return Money::fromDatabase((string) $withdrawal->amount, $withdrawal->currency);
    }

    /**
     * The reservation this request is supposed to be holding must still exist.
     *
     * @throws WithdrawalException
     */
    private function assertReservationIntact(Withdrawal $withdrawal, Wallet $wallet): void
    {
        $amount = $this->amountOf($withdrawal);
        $held = $this->holds->heldAmount($wallet);

        if ($held->isGreaterThanOrEqualTo($amount)) {
            return;
        }

        throw WithdrawalException::noReservation(
            (int) $withdrawal->getKey(),
            (int) $wallet->getKey(),
            $amount->toString(),
            $held->toString(),
        );
    }

    /**
     * A settled withdrawal must never re-enter the review flow.
     *
     * @throws WithdrawalException
     */
    private function assertNotAlreadySettled(Withdrawal $withdrawal): void
    {
        if ($withdrawal->status === WithdrawalStatus::Completed || $withdrawal->financial_transaction_id !== null) {
            throw WithdrawalException::alreadyCompleted(
                (int) $withdrawal->getKey(),
                $withdrawal->financial_transaction_id,
            );
        }
    }

    /**
     * @throws WithdrawalException
     */
    private function assertCurrencyMatches(Withdrawal $withdrawal, Wallet $wallet): void
    {
        if ($withdrawal->currency === $wallet->currency) {
            return;
        }

        throw WithdrawalException::currencyMismatch(
            (int) $wallet->getKey(),
            $wallet->currency,
            $withdrawal->currency,
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
     * @param  array<string, mixed>  $additions
     * @return array<string, mixed>
     */
    private function mergeMetadata(Withdrawal $withdrawal, array $additions): array
    {
        $existing = is_array($withdrawal->metadata) ? $withdrawal->metadata : [];

        return array_merge($existing, $additions);
    }

    private function withinTransaction(\Closure $callback): mixed
    {
        return DB::transaction($callback);
    }
}
