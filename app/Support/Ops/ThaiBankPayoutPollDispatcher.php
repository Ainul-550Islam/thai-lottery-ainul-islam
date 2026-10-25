<?php

declare(strict_types=1);

namespace App\Support\Ops;

use App\Enums\ThaiBankPayoutState;
use App\Jobs\PollThaiBankPayoutStatusJob;
use App\Models\ThaiBankPayoutAttempt;
use Illuminate\Support\Facades\Log;

/**
 * The scheduled sweep that keeps unknown Thai bank payouts moving.
 *
 * WHY A SWEEP AND NOT A FIXED JOB
 * -------------------------------
 * PollThaiBankPayoutStatusJob reconciles ONE attempt, and the dispatch path
 * already schedules it per attempt with a delay. A sweep exists for the case
 * the per-attempt path cannot cover: a queue that lost a delayed job, a worker
 * that died between the dispatch and the run, or an attempt that was written
 * by an operator tool. Without a sweep those payouts would sit in an
 * outcome-unknown state forever, holding a player's money.
 *
 * THE SWEEP NEVER SENDS MONEY
 * ---------------------------
 * It only DISPATCHES the reconciler, which itself only queries status. The
 * job is ShouldBeUnique per attempt and re-checks the configured poll
 * interval, so a sweep that overlaps a delayed dispatch cannot double-poll,
 * and it can never turn into a second transfer.
 *
 * FAIL-OPEN ON DISPATCH ERRORS
 * ----------------------------
 * One attempt that cannot be dispatched (a queue that is down) must not stop
 * the sweep from reaching the others, and it must not raise the process: the
 * failure is logged with the attempt id only, never with a destination.
 */
final class ThaiBankPayoutPollDispatcher
{
    /**
     * Dispatch a poll for every attempt that is due.
     *
     * @return int how many polls were dispatched
     */
    public function dispatchDue(): int
    {
        $interval = max(1, (int) config('thai_payout.poll_after_seconds', 60));
        $maxAge = max(1, (int) config('thai_payout.poll_max_age_hours', 24));

        $attempts = ThaiBankPayoutAttempt::query()
            ->whereIn('state', [
                ThaiBankPayoutState::Accepted->value,
                ThaiBankPayoutState::Uncertain->value,
            ])
            ->where('created_at', '>=', now()->subHours($maxAge))
            ->where(function ($query) use ($interval): void {
                $query->whereNull('last_polled_at')
                    ->orWhere('last_polled_at', '<=', now()->subSeconds($interval));
            })
            ->orderBy('id')
            ->limit(100)
            ->get(['id']);

        $dispatched = 0;

        foreach ($attempts as $attempt) {
            try {
                PollThaiBankPayoutStatusJob::dispatch((int) $attempt->getKey());
                $dispatched++;
            } catch (\Throwable $exception) {
                Log::warning('ops.thai_payout_poll_dispatch_failed', [
                    'attempt_id' => (int) $attempt->getKey(),
                    'exception_class' => $exception::class,
                ]);
            }
        }

        return $dispatched;
    }
}
