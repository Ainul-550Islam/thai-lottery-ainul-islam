<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\WalletHoldType;
use App\Enums\WithdrawalApprovalStatus;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\WithdrawalException;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The review decision on a withdrawal, and the reservation of the funds.
 *
 * WHAT APPROVAL MEANS FINANCIALLY
 * -------------------------------
 * Approving a withdrawal RESERVES the amount: it moves from available to locked
 * inside the same wallet. The total balance does not change, no value leaves the
 * system and therefore no ledger entry is written yet. The money actually leaves
 * in WithdrawalCompletionService, which consumes the reservation and posts a
 * balanced double entry.
 *
 * Reserving at approval time is the point of the whole design: from this moment
 * the customer cannot spend the same money on a bet, and a second withdrawal
 * cannot be approved against it.
 *
 * TWO SIMULTANEOUS APPROVALS CANNOT RESERVE THE SAME FUNDS
 * Three barriers, in this order:
 *   1. The WALLET row is taken with `FOR UPDATE` before anything is read, so two
 *      approvals against one wallet are serialised by the database. The second one
 *      does not see a stale available balance - it waits, then reads the balance
 *      the first one left behind.
 *   2. The WITHDRAWAL row is then also taken with `FOR UPDATE`, so two approvals
 *      of the SAME request cannot both see status `pending`: the second sees
 *      `approved` and is refused as not approvable.
 *   3. WalletHoldService re-checks the available balance under that same wallet
 *      lock before it increases locked_balance, and the database CHECK constraint
 *      `locked_balance <= balance` is the final backstop.
 * So of two 80.00 approvals against 100.00 available, at most one succeeds and the
 * other fails with InsufficientBalanceException. The available balance can never
 * go negative.
 *
 * LOCK ORDER: WALLET -> FINANCIAL ENTITY -> LEDGER ACCOUNTS, always, in every
 * method here. This matches DepositService, WithdrawalService, both completion
 * services and the Phase 2.1 engine, so no pair of services can deadlock by
 * taking the same two locks in opposite orders.
 *
 * NO PAYMENT GATEWAY. Approving sends nothing anywhere. There is no provider call,
 * no webhook and no credential in this file.
 *
 * SCHEMA NOTE: `withdrawals` really does have a review trail - reviewed_by,
 * reviewed_at, approved_at, rejected_at, rejection_reason - so unlike deposits,
 * the decision is written to real indexed columns. There is still no
 * `approval_status` column, so WithdrawalApprovalStatus is only ever DERIVED from
 * those timestamps and is never persisted.
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
        // MONEY-OUT KYC GATE, BEFORE THE BOUNDARY OPENS: identity certification
        // must happen before any fund is reserved. The gate runs under its own
        // transaction (it can legally never open inside ours), detains the
        // withdrawal into KycRequired when evidence is inadmissible, and lifts a
        // previous detention back to Pending when valid evidence lands — the
        // canApprove() lane below then reads a clean state either way.
        $current = $withdrawal->fresh() ?? $withdrawal;

        if ($current instanceof Withdrawal && $this->kycGate->requiresGate($current)) {
            $this->kycGate->gate($current);
            $withdrawal = $current;
        }

        return $this->withinTransaction(function () use ($withdrawal, $reviewerUserId, $note): array {
            // 1. WALLET lock first, always.
            $wallet = $this->locks->lock((int) $withdrawal->wallet_id);

            // 2. FINANCIAL ENTITY lock second.
            $current = $this->transitions->lockWithdrawal($withdrawal);

            $this->assertWalletMatches($current, $wallet);
            app(WithdrawalIntegrityGuard::class)->assertTerms($current, $wallet);
            app(\App\Services\Payment\WithdrawalProviderAccountService::class)->assertActive($current);
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

            // 3. Reserve with durable reservation ownership (transfers any initial hold cleanly).
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
     * The release is idempotent, so re-running a rejection cannot release the
     * amount twice and cannot create money. The total balance is unchanged by the
     * whole operation: only the split between available and locked moves back.
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
            app(WithdrawalIntegrityGuard::class)->assertTerms($current, $wallet);
            app(WithdrawalInitialHoldService::class)->assertRequiredExists($current, $wallet);
            $this->assertNotAlreadySettled($current);

            if (! $current->status->canReject()) {
                throw WithdrawalException::notRejectable($current->id, $current->status);
            }

            $released = false;
            $workingWallet = $wallet;

            if ($current->status->holdsReservedFunds()) {
                $outcome = app(WithdrawalReservationService::class)->release($current, $wallet, false);
                $workingWallet = $outcome['wallet'];
                $released = $outcome['released'];
            } elseif (app(WithdrawalInitialHoldService::class)->exists($current)) {
                $outcome = app(WithdrawalInitialHoldService::class)->releasePending($current, $wallet);
                $workingWallet = $outcome['wallet'];
                $released = $outcome['released'];
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
     * The reservation stays exactly where it is: this only records that the payout
     * has been picked up. No balance changes.
     *
     * @throws WithdrawalException
     * @throws FinancialException
     */
    public function markProcessing(Withdrawal $withdrawal): Withdrawal
    {
        // CLIFF-EDGE KYC RECHECK, BEFORE THE IRREVERSIBLE FLIP: the approval
        // gesture and this moment can be seconds (or a queue delay) apart, and
        // identity evidence can expire in between. The VerifyWithdrawalKycJob's
        // verdict is the integration contract: a detention aborts processing
        // HERE — the gate already moved the withdrawal to KycRequired, and the
        // reservation back-off is the operator's explicit next act, so no money
        // quietly slips out under stale identity.
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

            app(\App\Services\Payment\WithdrawalProviderAccountService::class)->assertActive($current);
            app(WithdrawalReservationService::class)->assertActive($current, $wallet);
            $this->assertReservationIntact($current, $wallet);

            return $this->transitions->transitionWithdrawal($current, WithdrawalStatus::Processing, [
                'metadata' => $this->mergeMetadata($current, [
                    'processing_started_at' => Carbon::now()->toIso8601String(),
                ]),
            ]);
        });
    }

    /**
     * Record a pre-disbursement failure and release the reservation.
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
     * Provider transport exceptions must never call this method: their outcome is
     * unknown and the withdrawal remains Processing with its wallet hold intact.
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
     * Legacy reference-only route is retained as a fail-closed compatibility
     * boundary. A typed string, even from a second operator, cannot prove that
     * an uncertain transfer was not paid. Use the existing maker/checker
     * WithdrawalReconciliationService and its archived evidence instead.
     *
     * @return array{withdrawal: Withdrawal, wallet: Wallet, hold_released: bool}
     * @throws FinancialException
     */
    public function markReconciledFailed(
        Withdrawal $withdrawal,
        int $operatorUserId,
        string $providerEvidenceReference,
    ): array {
        throw FinancialException::withCode(
            'withdrawal_reconciliation_requires_case',
            'A reviewed withdrawal reconciliation case is required; a reference alone cannot release this hold.',
            ['withdrawal_id' => (int) $withdrawal->getKey()],
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
            app(WithdrawalIntegrityGuard::class)->assertTerms($current, $wallet);
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

            $released = false;
            $workingWallet = $wallet;

            if ($current->status->holdsReservedFunds()) {
                if (app(WithdrawalReservationService::class)->exists($current)) {
                    $outcome = app(WithdrawalReservationService::class)->release($current, $wallet, $providerOutcomeConfirmed);
                } else {
                    $outcome = $this->holds->releaseIfHeld($wallet, $this->amountOf($current));
                }
                $workingWallet = $outcome['wallet'];
                $released = $outcome['released'];
            } elseif (app(WithdrawalInitialHoldService::class)->exists($current)) {
                $outcome = app(WithdrawalInitialHoldService::class)->releasePending($current, $wallet);
                $workingWallet = $outcome['wallet'];
                $released = $outcome['released'];
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
        if (DB::transactionLevel() > 0) {
            return $callback();
        }

        return DB::transaction($callback);
    }
}
