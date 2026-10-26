<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\PayoutBatchStatus;
use App\Jobs\ExecutePayoutTransferJob;
use App\Jobs\ProcessPayoutBatchJob;
use App\Models\PayoutBatch;
use App\Models\User;
use App\Services\Payment\PayoutTransferAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** Atomic SQL batch claim plus durable database queue insertion, never a provider invocation. */
final class PayoutBatchRoutingService
{
    public function dispatchApproved(PayoutBatch $batch, ?User $actor = null): array
    {
        if ($actor) {
            app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::ROUTE);
        }
        $guard = app(PayoutBatchMembershipService::class);
        $connection = config('queue.connections.database.connection');
        if (config('finance.prize_payout.safety_mode') !== 'LIVE' || config('queue.connections.database.driver') !== 'database'
            || config('queue.connections.database.table') !== 'jobs'
            || ($connection !== null && $connection !== DB::getDefaultConnection())) {
            $guard->refuse();
        }

        return DB::transaction(function () use ($batch, $actor, $guard): array {
            if ($actor) {
                app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::ROUTE);
            }
            // Acquire decisive member locks before the shared batch claim. Two
            // contenders must both wait on the original obligation, not chain
            // behind a batch lock held by another waiter.
            $guard->members($batch, true);
            $claim = app(PayoutBatchService::class)->claim($batch);
            $row = $claim['batch'];
            if (! $claim['claimed']) {
                return ['batch' => $row, 'enqueued' => false];
            }
            $members = $guard->members($row);
            if ($actor && $members->contains(fn ($p) => (int) $p->user_id === (int) $actor->id)) {
                $guard->refuse();
            }
            $lane = $row->metadata['execution_lane'];
            $job = $lane === 'wallet'
                ? new ProcessPayoutBatchJob((int) $row->id, $members->pluck('id')->map(fn ($id) => (int) $id)->all())
                : new ExecutePayoutTransferJob($row->batch_key);
            $job->onConnection('database')->beforeCommit();
            // Same SQL connection and transaction as the durable claim: no post-commit enqueue gap.
            Queue::connection('database')->push($job);
            $metadata = $row->metadata;
            $metadata['queued_at'] = now()->toIso8601String();
            $metadata['queued_by'] = $actor?->id;
            $row->metadata = $metadata;
            $row->save();

            return ['batch' => $row, 'enqueued' => true];
        }, 3);
    }

    public function project(PayoutBatch $row): array
    {
        return ['id' => (int) $row->id, 'batch_key' => $row->batch_key, 'status' => $row->status->value,
            'method' => $row->metadata['execution_lane'] ?? null, 'currency' => $row->currency->value,
            'member_count' => (int) $row->member_count, 'processed_count' => (int) $row->processed_count,
            'paid_count' => (int) $row->paid_count, 'failed_count' => (int) $row->failed_count,
            'paid_amount' => (string) $row->paid_amount, 'aggregate_amount' => (string) $row->aggregate_amount,
            'automatic_resend_allowed' => false];
    }

    public function refresh(PayoutBatch $row, User $actor): PayoutBatch
    {
        app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::ROUTE);
        $current = app(PayoutBatchService::class)->refresh($row);
        if ($current->status === PayoutBatchStatus::Processing && $current->processed_count === $current->member_count) {
            return app(PayoutBatchService::class)->settle($current)['batch'];
        }

        return $current;
    }
}
