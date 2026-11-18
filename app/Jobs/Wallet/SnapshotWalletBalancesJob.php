<?php

declare(strict_types=1);

namespace App\Jobs\Wallet;

use App\Services\Wallet\WalletBalanceSnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Daily balance snapshot, chunked and idempotent.
 *
 * WHY A JOB AND NOT ONLY A COMMAND
 * The command is the operator's handle on the task (wallet:snapshot-balances); the
 * job is what the scheduler dispatches so the work happens off the request path
 * and can be retried by the queue like every other background task in this system.
 * A second implementation would be the problem, so the job does nothing but call
 * the same service the command calls.
 *
 * IDEMPOTENCY COMES FROM THE SCHEMA
 * The unique key (member_id, wallet_type, snapshot_date) plus updateOrCreate means a
 * retry, a duplicate dispatch or a manual re-run all converge on one row per day.
 * There is no "done" flag to get out of sync.
 */
final class SnapshotWalletBalancesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Long enough for a large member base on a slow disk, short enough to notice. */
    public int $timeout = 900;

    public function __construct(
        public readonly ?string $day = null,
        public readonly int $chunk = 500,
    ) {
    }

    public function handle(WalletBalanceSnapshotService $snapshots): void
    {
        $day = $this->day === null ? Carbon::now() : Carbon::parse($this->day);

        $snapshots->captureAll($day, $this->chunk);
    }

    public function tags(): array
    {
        return ['wallet-snapshot', 'day:'.($this->day ?? 'today')];
    }
}
