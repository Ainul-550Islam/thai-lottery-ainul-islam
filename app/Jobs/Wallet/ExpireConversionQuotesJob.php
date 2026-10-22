<?php

declare(strict_types=1);

namespace App\Jobs\Wallet;

use App\Enums\QueueName;
use App\Services\Wallet\Conversion\ConversionQuoteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The queued form of the quote sweep.
 *
 * WHY BOTH A COMMAND AND A JOB EXIST
 * The command is the operator's handle: run it now, dry-run it, read its output.
 * The job is what the scheduler and any future workflow should use, because a
 * sweep that grows with the member base must not run inside the scheduler's own
 * process where a slow pass delays every other timer. The command dispatches
 * this job synchronously when it wants the answer immediately, and the schedule
 * dispatches it to a queue; the logic lives in exactly one place either way.
 *
 * WHY `ShouldBeUnique`
 * Two concurrent sweeps would race on the same rows. The update is idempotent
 * (an already-expired quote does not expire again), so the worst case is wasted
 * work rather than corruption — but on a money table, "wasted work" is still a
 * lock held against a member's quote read, so the job takes a uniqueness lock
 * for a short window and refuses to be its own duplicate.
 */
class ExpireConversionQuotesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $batchSize = 500,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * @return int the number of quotes expired
     */
    public function handle(ConversionQuoteService $quotes): int
    {
        $expired = $quotes->expireStale();

        Log::info('conversion.quotes_expired', [
            'expired' => $expired,
            'batch_size' => $this->batchSize,
        ]);

        return $expired;
    }

    public function uniqueId(): string
    {
        // One sweep at a time across the whole platform, not one per worker: the
        // work is global and the rows do not belong to any single member.
        return 'wallet-conversion:expire-quotes';
    }
}
