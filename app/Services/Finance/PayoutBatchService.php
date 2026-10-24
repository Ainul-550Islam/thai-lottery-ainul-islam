<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Finance\PayoutBatchData;
use App\Enums\AuditAction;
use App\Enums\PayoutBatchStatus;
use App\Enums\PayoutStatus;
use App\Enums\RiskLevel;
use App\Events\PayoutBatchCompleted;
use App\Exceptions\PayoutBatchException;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Models\PayoutBatch;
use App\Models\PrizeDisbursement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class PayoutBatchService
{
    public const MEMBER_METADATA_KEY = 'batch';

    /**
     * @return array{batch: PayoutBatch, created: bool}
     *
     * @throws PayoutBatchException
     */
    public function create(PayoutBatchData $data): array
    {
        if (! $data->hasMembers()) {
            throw PayoutBatchException::emptyMemberList($data->batchKey());
        }

        if (! $data->aggregateIsWellFormed() || count($data->payoutReferences) !== count($data->canonicalReferences())) {
            throw new PayoutBatchException(
                sprintf('Payout batch [%s]: aggregate amount or member reference list is invalid.', $data->batchKey()),
                PayoutBatchException::CODE_AGGREGATE_MISMATCH,
                ['batch_key' => $data->batchKey(), 'claimed' => $data->aggregateAmount, 'actual' => 'malformed'],
            );
        }

        return DB::transaction(function () use ($data): array {
            $members = Payout::query()->lockForUpdate()
                ->whereIn('reference_number', $data->canonicalReferences())
                ->orderBy('id')
                ->get();

            if ($members->count() !== count($data->canonicalReferences())) {
                throw new PayoutBatchException(
                    sprintf(
                        'Payout batch [%s]: %d of %d named payouts resolved to rows; the batch cannot plan money it cannot see.',
                        $data->batchKey(),
                        $members->count(),
                        count($data->canonicalReferences()),
                    ),
                    PayoutBatchException::CODE_NOT_FOUND,
                    ['batch_key' => $data->batchKey()],
                );
            }

            $approvalGuard = app(PayoutApprovalGuard::class);
            $membershipGuard = app(PayoutBatchMembershipService::class);
            $lane = null;
            $sum = '0.00';

            foreach ($members as $member) {
                if ($member->trashed() || $member->currency !== $data->currency
                    || PrizeDisbursement::query()->where('payout_id', $member->id)->exists()) {
                    $membershipGuard->refuse();
                }

                try {
                    $approvalGuard->assertApproved($member, $member->status === PayoutStatus::Completed);
                    $method = $approvalGuard->method($member)->value;
                } catch (Throwable) {
                    $membershipGuard->refuse();
                }

                if ($lane !== null && $lane !== $method) {
                    $membershipGuard->refuse();
                }
                $lane = $method;
                $sum = bcadd($sum, (string) $member->amount, 2);
            }

            if (bccomp($sum, $data->canonicalAggregate(), 2) !== 0) {
                throw PayoutBatchException::aggregateMismatch(
                    $data->batchKey(),
                    $data->canonicalAggregate(),
                    $sum,
                );
            }

            $existing = PayoutBatch::query()->lockForUpdate()
                ->where('batch_key', $data->batchKey())
                ->first();

            if ($existing instanceof PayoutBatch) {
                $membershipGuard->verify($existing, $members);

                return ['batch' => $existing, 'created' => false];
            }

            foreach ($members as $member) {
                if ($member->status !== PayoutStatus::Pending
                    || $member->financial_transaction_id !== null
                    || array_key_exists(self::MEMBER_METADATA_KEY, $member->metadata ?? [])
                    || DB::table('payout_batch_members')->where('payout_id', $member->id)->exists()) {
                    $membershipGuard->refuse();
                }
            }

            $batch = new PayoutBatch;
            $batch->batch_key = $data->batchKey();
            $batch->status = PayoutBatchStatus::Pending;
            $batch->currency = $data->currency;
            $batch->member_count = $members->count();
            $batch->aggregate_amount = $data->canonicalAggregate();
            $batch->note = $data->note;
            $batch->metadata = [
                'execution_lane' => $lane,
                'context' => $data->context,
                'created_via' => 'payout-batch-service',
            ];
            $batch->save();

            foreach ($members as $member) {
                DB::table('payout_batch_members')->insert([
                    'payout_batch_id' => $batch->id,
                    'payout_id' => $member->id,
                    'facts_hash' => $approvalGuard->fingerprint($member),
                    'method' => $lane,
                    'currency' => $member->currency->value,
                    'amount' => (string) $member->amount,
                    'created_at' => now(),
                ]);

                $metadata = is_array($member->metadata) ? $member->metadata : [];
                $metadata[self::MEMBER_METADATA_KEY] = [
                    'batch_key' => $data->batchKey(),
                    'joined_at' => Carbon::now()->toIso8601String(),
                    'outcome' => 'queued',
                ];
                $member->metadata = $metadata;
                $member->save();
            }

            $this->recordAudit($batch, sprintf(
                'Payout batch created: %d member(s), aggregate %s %s (%s).',
                $members->count(),
                $data->canonicalAggregate(),
                $data->currency->value,
                (string) $lane,
            ), RiskLevel::Medium);

            return ['batch' => $batch, 'created' => true];
        }, 3);
    }

    /**
     * @return array{batch: PayoutBatch, claimed: bool}
     */
    public function claim(PayoutBatch $batch): array
    {
        if (config('finance.prize_payout.safety_mode') !== 'LIVE') {
            app(PayoutBatchMembershipService::class)->refuse();
        }

        return DB::transaction(function () use ($batch): array {
            $membership = app(PayoutBatchMembershipService::class);
            $members = $membership->members($batch, true);
            $locked = PayoutBatch::query()->lockForUpdate()->find((int) $batch->getKey());

            if (! $locked instanceof PayoutBatch) {
                throw PayoutBatchException::notFound($batch->batch_key);
            }

            $membership->verify($locked, $members);
            foreach ($members as $member) {
                try {
                    app(PayoutApprovalGuard::class)->assertApproved($member, $member->status === PayoutStatus::Completed);
                } catch (Throwable) {
                    $membership->refuse();
                }
            }

            if (in_array($locked->status, [PayoutBatchStatus::Processing, PayoutBatchStatus::Completed], true)) {
                $this->syncCaller($batch, $locked);

                return ['batch' => $locked, 'claimed' => false];
            }

            if ($locked->status !== PayoutBatchStatus::Pending || $locked->claimed_at !== null) {
                $membership->refuse();
            }

            $locked->status = PayoutBatchStatus::Processing;
            $locked->claimed_at = Carbon::now();
            $locked->failure_reason = null;
            $locked->save();

            $this->syncCaller($batch, $locked);

            return ['batch' => $locked, 'claimed' => true];
        }, 3);
    }

    public function claimForExecution(PayoutBatch $batch): PayoutBatch
    {
        $result = $this->claim($batch);
        if (! $result['claimed']) {
            throw PayoutBatchException::transitionRace($batch->batch_key);
        }

        return $result['batch'];
    }

    public function refresh(PayoutBatch $batch): PayoutBatch
    {
        return DB::transaction(function () use ($batch): PayoutBatch {
            $membership = app(PayoutBatchMembershipService::class);
            $members = $membership->members($batch, true);
            $locked = PayoutBatch::query()->lockForUpdate()->find((int) $batch->getKey());
            if (! $locked instanceof PayoutBatch) {
                throw PayoutBatchException::notFound($batch->batch_key);
            }

            $rollup = $membership->rollup($locked, $members);
            $locked->forceFill($rollup)->save();
            $this->syncCaller($batch, $locked);

            return $locked;
        }, 3);
    }

    /**
     * @param  'paid'|'failed'|'replayed'  $outcome
     */
    public function recordOutcome(PayoutBatch $batch, string $payoutReference, string $outcome, string $amount): PayoutBatch
    {
        return DB::transaction(function () use ($batch, $payoutReference, $outcome, $amount): PayoutBatch {
            $membership = app(PayoutBatchMembershipService::class);
            $member = Payout::query()->lockForUpdate()->where('reference_number', $payoutReference)->first();
            if (! $member instanceof Payout || (int) $membership->assertMember($member)->id !== (int) $batch->id
                || bccomp($amount, (string) $member->amount, 2) !== 0) {
                $membership->refuse();
            }

            $valid = match ($outcome) {
                'paid', 'replayed' => $member->status === PayoutStatus::Completed,
                'failed' => $member->status === PayoutStatus::Failed,
                default => false,
            };
            if (! $valid) {
                $membership->refuse();
            }

            return $this->refresh($batch);
        }, 3);
    }

    public function recordMemberOutcome(PayoutBatch $batch, string $payoutReference, string $outcome, string $amount): PayoutBatch
    {
        return $this->recordOutcome($batch, $payoutReference, $outcome, $amount);
    }

    /**
     * @return array{batch: PayoutBatch, completed: bool}
     */
    public function settle(PayoutBatch $batch, ?string $failureReason = null): array
    {
        return DB::transaction(function () use ($batch, $failureReason): array {
            $membership = app(PayoutBatchMembershipService::class);
            $members = $membership->members($batch, true);
            $locked = PayoutBatch::query()->lockForUpdate()->find((int) $batch->getKey());

            if (! $locked instanceof PayoutBatch) {
                throw PayoutBatchException::notFound($batch->batch_key);
            }

            $rollup = $membership->rollup($locked, $members);
            $locked->forceFill($rollup)->save();

            if ($locked->status === PayoutBatchStatus::Completed) {
                if ($failureReason !== null || (int) $locked->processed_count !== (int) $locked->member_count) {
                    $membership->refuse();
                }
                $this->syncCaller($batch, $locked);

                return ['batch' => $locked, 'completed' => false];
            }

            if ($locked->status !== PayoutBatchStatus::Processing) {
                throw PayoutBatchException::stateForbids(
                    $locked->batch_key,
                    $locked->status->value,
                    $failureReason === null ? PayoutBatchStatus::Completed->value : PayoutBatchStatus::Failed->value,
                );
            }

            if ($failureReason !== null || (int) $locked->processed_count < (int) $locked->member_count
                || bccomp((string) $locked->paid_amount, (string) $locked->aggregate_amount, 2) > 0) {
                $membership->refuse();
            }

            $locked->status = PayoutBatchStatus::Completed;
            $locked->completed_at = Carbon::now();
            $locked->save();

            event(new PayoutBatchCompleted(
                batch: $locked,
                memberCount: (int) $locked->member_count,
                paidCount: (int) $locked->paid_count,
                failedCount: (int) $locked->failed_count,
                replayedCount: (int) $locked->replayed_count,
                paidAmount: (string) $locked->paid_amount,
            ));

            $this->recordAudit($locked, sprintf(
                'Payout batch completed: %d paid, %d failed, %d replayed; paid %s %s of planned %s.',
                $locked->paid_count,
                $locked->failed_count,
                $locked->replayed_count,
                (string) $locked->paid_amount,
                $locked->currency->value,
                (string) $locked->aggregate_amount,
            ), RiskLevel::Medium);

            $this->syncCaller($batch, $locked);

            return ['batch' => $locked, 'completed' => true];
        }, 3);
    }

    public function cancel(PayoutBatch $batch, string $reason, ?int $operatorUserId = null): PayoutBatch
    {
        return DB::transaction(function () use ($batch, $reason, $operatorUserId): PayoutBatch {
            $membership = app(PayoutBatchMembershipService::class);
            $members = $membership->members($batch, true);
            $locked = PayoutBatch::query()->lockForUpdate()->find((int) $batch->getKey());

            if (! $locked instanceof PayoutBatch) {
                throw PayoutBatchException::notFound($batch->batch_key);
            }

            $membership->verify($locked, $members);
            if ($locked->status !== PayoutBatchStatus::Pending || $locked->claimed_at !== null
                || $members->contains(fn ($m) => $m->status !== PayoutStatus::Pending || $m->financial_transaction_id !== null || array_key_exists('transfer', $m->metadata ?? []))) {
                throw PayoutBatchException::stateForbids(
                    $locked->batch_key,
                    $locked->status->value,
                    PayoutBatchStatus::Cancelled->value,
                );
            }

            $locked->status = PayoutBatchStatus::Cancelled;
            $locked->cancelled_at = Carbon::now();
            $locked->failure_reason = $reason;
            $locked->save();

            $this->recordAudit($locked, sprintf(
                'Payout batch cancelled%s: %s.',
                $operatorUserId !== null ? sprintf(' by operator #%d', $operatorUserId) : '',
                $reason,
            ), RiskLevel::Medium);

            $this->syncCaller($batch, $locked);

            return $locked;
        }, 3);
    }

    public function findByKey(string $batchKey): ?PayoutBatch
    {
        return PayoutBatch::query()->where('batch_key', $batchKey)->first();
    }

    public function statusOf(PayoutBatch $batch): PayoutBatchStatus
    {
        $fresh = PayoutBatch::query()->findOrFail((int) $batch->getKey());

        return $fresh->status;
    }

    private function syncCaller(PayoutBatch $caller, PayoutBatch $fresh): void
    {
        $caller->setRawAttributes($fresh->getAttributes(), true);
        $caller->syncChanges();
    }

    private function recordAudit(PayoutBatch $batch, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => $riskLevel,
            'auditable_type' => PayoutBatch::class,
            'auditable_id' => $batch->getKey(),
            'description' => $description,
            'metadata' => [
                'batch_key' => $batch->batch_key,
                'batch_status' => $batch->status->value,
                'aggregate_amount' => (string) $batch->aggregate_amount,
                'currency' => $batch->currency->value,
                'lane' => 'payout_batch',
            ],
        ]);

        $log->save();
    }
}
