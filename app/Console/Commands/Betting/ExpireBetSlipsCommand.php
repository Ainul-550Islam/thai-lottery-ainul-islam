<?php

declare(strict_types=1);

namespace App\Console\Commands\Betting;

use App\Jobs\Betting\ExpireBetSlipsJob;
use Illuminate\Console\Command;

/**
 * bet-slips:expire — the operator handle on the expiry sweep.
 *
 * It dispatches the job rather than doing the work itself, so there is exactly one
 * implementation of "expire the drafts" and the scheduled command is a trigger, not
 * a second code path.
 */
class ExpireBetSlipsCommand extends Command
{
    protected $signature = 'bet-slips:expire';

    protected $description = 'Mark draft bet slips whose TTL has elapsed as expired.';

    public function handle(): int
    {
        ExpireBetSlipsJob::dispatch();

        if ($this->output->isVerbose()) {
            $this->info('Bet slip expiry sweep dispatched.');
        }

        return self::SUCCESS;
    }
}
