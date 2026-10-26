<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\PaymentStatus;
use App\Enums\RiskLevel;
use App\Enums\ThaiBankPayoutState;
use App\Enums\WithdrawalStatus;
use App\Models\Payment;
use App\Models\ThaiBankPayoutAttempt;
use App\Models\Withdrawal;
use App\Services\Audit\AuditLogService;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Lock\DistributedLockService;
use App\Services\Payment\PaymentLogSanitizer;
use App\Services\Payout\ThaiBank\KbankPayoutAdapter;
use App\Services\Payout\ThaiBank\ScbPayoutAdapter;
use App\Services\Payout\ThaiBank\ThaiBankPayoutWebhookService;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Reconciles confirmed local outcomes and polls status; it never submits or repeats a payout. */
final class PollThaiBankPayoutStatusJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // Polling is capped by poll_max_age_hours; do not discard an unresolved hold after a small attempt count.
    public int $tries = 0;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $attemptId) {}

    public function uniqueId(): string
    {
        return (string) $this->attemptId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function handle(
        DistributedLockService $locks,
        ThaiBankPayoutWebhookService $outcomes,
        AuditLogService $audit,
    ): void {
        try {
            $result = $locks->runWithLock('thai-bank-payout:poll:'.$this->attemptId, function () use ($outcomes, $audit): string {
                $snapshot = ThaiBankPayoutAttempt::query()->find($this->attemptId);
                if (! $snapshot instanceof ThaiBankPayoutAttempt) {
                    return 'done';
                }
                if ($snapshot->state === ThaiBankPayoutState::Rejected) {
                    $this->finalizeRejectedAttempt($snapshot, $audit);

                    return 'done';
                }
                if (! in_array($snapshot->state, [ThaiBankPayoutState::Accepted, ThaiBankPayoutState::Uncertain], true)) {
                    return 'done';
                }
                $maxAge = max(1, (int) config('thai_payout.poll_max_age_hours', 24));
                if ($snapshot->created_at !== null && $snapshot->created_at->lt(now()->subHours($maxAge))) {
                    $this->flagForManualReconciliation($snapshot, $audit);

                    return 'done';
                }
                $interval = max(1, (int) config('thai_payout.poll_after_seconds', 60));
                $lastPoll = $snapshot->last_polled_at ?? $snapshot->created_at;
                if ($lastPoll !== null && $lastPoll->gt(now()->subSeconds($interval))) {
                    return 'wait';
                }

                DB::transaction(function () use ($snapshot): void {
                    $current = ThaiBankPayoutAttempt::query()->lockForUpdate()->find($snapshot->getKey());
                    if ($current instanceof ThaiBankPayoutAttempt
                        && in_array($current->state, [ThaiBankPayoutState::Accepted, ThaiBankPayoutState::Uncertain], true)) {
                        $current->forceFill(['last_polled_at' => now()])->save();
                    }
                }, 3);

                $adapter = match ($snapshot->bank) {
                    'scb' => app(ScbPayoutAdapter::class),
                    'kbank' => app(KbankPayoutAdapter::class),
                    default => null,
                };
                if ($adapter === null || ! $adapter->configured()
                    || ! (bool) config('thai_payout.enabled', false)
                    || ! (bool) config('thai_payout.banks.'.$snapshot->bank.'.enabled', false)) {
                    $audit->log(
                        userId: null,
                        action: AuditAction::Reconcile,
                        riskLevel: RiskLevel::High,
                        auditable: $snapshot,
                        description: 'Thai bank payout polling is not configured; hold remains reserved for manual reconciliation.',
                        metadata: ['attempt_id' => (int) $snapshot->getKey(), 'bank' => $snapshot->bank],
                    );

                    return 'done';
                }

                $reference = $snapshot->provider_reference ?? $snapshot->withdrawal_reference;
                $outcome = $adapter->queryStatus($reference);
                $outcomes->applyProviderOutcome($snapshot, $outcome, 'poller');
                $fresh = $snapshot->fresh();

                return $fresh instanceof ThaiBankPayoutAttempt
                    && in_array($fresh->state, [ThaiBankPayoutState::Accepted, ThaiBankPayoutState::Uncertain], true)
                    ? 'wait'
                    : 'done';
            }, ttlSeconds: 60, maxAttempts: 2);

            if ($result === 'wait') {
                $this->release(max(1, (int) config('thai_payout.poll_after_seconds', 60)));
            }
        } catch (Throwable $exception) {
            Log::warning('payment.thai_bank_payout_poll_failed', PaymentLogSanitizer::sanitize([
                'attempt_id' => $this->attemptId,
                'exception_class' => $exception::class,
            ]));
            $attempt = ThaiBankPayoutAttempt::query()->find($this->attemptId);
            if ($attempt instanceof ThaiBankPayoutAttempt
                && (! $attempt->state->isTerminal() || $attempt->state === ThaiBankPayoutState::Rejected)) {
                $this->release(max(1, (int) config('thai_payout.poll_after_seconds', 60)));
            }
        }
    }

    private function finalizeRejectedAttempt(ThaiBankPayoutAttempt $attempt, AuditLogService $audit): void
    {
        $source = Withdrawal::query()->find($attempt->withdrawal_id);
        if (! $source instanceof Withdrawal) {
            $audit->log(
                userId: null,
                action: AuditAction::Reconcile,
                riskLevel: RiskLevel::High,
                auditable: $attempt,
                description: 'Confirmed Thai bank rejection has no linked withdrawal; reconciliation is required.',
                metadata: ['attempt_id' => (int) $attempt->getKey(), 'bank' => $attempt->bank],
            );

            return;
        }

        DB::transaction(function () use ($source, $attempt, $audit): void {
            app(WalletLockService::class)->lock((int) $source->wallet_id);
            $withdrawal = Withdrawal::query()->lockForUpdate()->find($source->getKey());
            $current = ThaiBankPayoutAttempt::query()->lockForUpdate()->find($attempt->getKey());
            if (! $withdrawal instanceof Withdrawal || ! $current instanceof ThaiBankPayoutAttempt
                || $current->state !== ThaiBankPayoutState::Rejected
                || (int) $current->withdrawal_id !== (int) $withdrawal->getKey()) {
                return;
            }
            if ($withdrawal->status === WithdrawalStatus::Failed) {
                return;
            }
            if (! in_array($withdrawal->status, [WithdrawalStatus::Approved, WithdrawalStatus::Processing], true)) {
                $audit->log(
                    userId: null,
                    action: AuditAction::Reconcile,
                    riskLevel: RiskLevel::High,
                    auditable: $current,
                    description: 'Confirmed Thai bank rejection conflicts with the local withdrawal state; no transition was applied.',
                    metadata: [
                        'attempt_id' => (int) $current->getKey(),
                        'withdrawal_id' => (int) $withdrawal->getKey(),
                        'withdrawal_state' => $withdrawal->status->value,
                    ],
                );

                return;
            }

            $payment = Payment::withTrashed()
                ->where('payable_type', Withdrawal::class)
                ->where('payable_id', $withdrawal->getKey())
                ->lockForUpdate()
                ->first();
            if ($payment instanceof Payment && (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Authorized, PaymentStatus::Failed], true)
                || ($current->provider_reference !== null && $payment->gateway_reference !== null
                    && $payment->gateway_reference !== $current->provider_reference))) {
                $audit->log(
                    userId: null,
                    action: AuditAction::Reconcile,
                    riskLevel: RiskLevel::High,
                    auditable: $current,
                    description: 'Confirmed Thai bank rejection conflicts with linked payment facts; the withdrawal hold remains reserved.',
                    metadata: ['attempt_id' => (int) $current->getKey(), 'withdrawal_id' => (int) $withdrawal->getKey()],
                );

                return;
            }

            app(WithdrawalApprovalService::class)->markProviderFailed($withdrawal);
            if ($payment instanceof Payment) {
                $payment->forceFill([
                    'status' => PaymentStatus::Failed,
                    'failed_at' => $payment->failed_at ?? now(),
                    'failure_reason' => 'provider_rejected',
                    'gateway_reference' => $current->provider_reference ?? $payment->gateway_reference,
                    'gateway_response' => [
                        'gateway' => 'thai_bank',
                        'outcome' => 'failed',
                        'state' => 'rejected',
                        'provider_reference' => $current->provider_reference,
                        'payout_rail' => 'thai_bank',
                        'payout_attempt_id' => (int) $current->getKey(),
                        'evidence_source' => 'deferred_dispatch_finalization',
                    ],
                ])->save();
            }
            $audit->log(
                userId: null,
                action: AuditAction::Payout,
                riskLevel: RiskLevel::Medium,
                auditable: $current,
                description: 'Confirmed Thai bank payout rejection was finalized after deferred disbursement handling.',
                metadata: ['attempt_id' => (int) $current->getKey(), 'withdrawal_id' => (int) $withdrawal->getKey()],
            );
        }, 3);
    }

    private function flagForManualReconciliation(ThaiBankPayoutAttempt $attempt, AuditLogService $audit): void
    {
        DB::transaction(function () use ($attempt, $audit): void {
            $current = ThaiBankPayoutAttempt::query()->lockForUpdate()->find($attempt->getKey());
            if (! $current instanceof ThaiBankPayoutAttempt || $current->state->isTerminal()) {
                return;
            }
            $current->forceFill([
                'outcome_unknown' => true,
                'raw_response' => PaymentLogSanitizer::sanitize(array_merge(is_array($current->raw_response) ? $current->raw_response : [], [
                    'manual_reconciliation_required' => true,
                    'manual_reconciliation_reason' => 'poll_age_exceeded',
                ])),
            ])->save();
            $withdrawal = Withdrawal::query()->find($current->withdrawal_id);
            if ($withdrawal instanceof Withdrawal) {
                $metadata = is_array($withdrawal->metadata) ? $withdrawal->metadata : [];
                $metadata['thai_bank_payout'] = array_merge(is_array($metadata['thai_bank_payout'] ?? null) ? $metadata['thai_bank_payout'] : [], [
                    'reconciliation_required' => true,
                    'reason' => 'poll_age_exceeded',
                    'attempt_id' => (int) $current->getKey(),
                ]);
                $withdrawal->forceFill(['metadata' => $metadata])->save();
            }
            $audit->log(
                userId: null,
                action: AuditAction::Reconcile,
                riskLevel: RiskLevel::High,
                auditable: $current,
                description: 'Thai bank payout exceeded its polling age and requires manual reconciliation; no outcome was guessed.',
                metadata: [
                    'attempt_id' => (int) $current->getKey(),
                    'withdrawal_id' => (int) $current->withdrawal_id,
                    'bank' => $current->bank,
                    'state' => $current->state->value,
                ],
            );
        }, 3);
    }
}
