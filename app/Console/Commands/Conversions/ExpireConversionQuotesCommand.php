<?php

declare(strict_types=1);

namespace App\Console\Commands\Conversions;

use App\Enums\WalletConversionStatus;
use App\Jobs\Wallet\ExpireConversionQuotesJob;
use App\Models\WalletConversionQuote;
use Illuminate\Console\Command;

/**
 * Expires quotes whose validity has passed.
 *
 * WHY THIS RUNS AT ALL, GIVEN AN EXPIRED QUOTE IS REFUSED ON USE
 * The refusal on use is the correctness control: a quote past its expiry cannot
 * be executed regardless of what this command does. What the sweep protects is
 * everything else — the `max_open_per_user` allowance, the member's own page,
 * and the reconciliation report, all of which would otherwise accumulate rows
 * that describe an intent nobody holds any more. A member who requests five
 * quotes while deciding is not abusing the feature; leaving those five open
 * forever would make the sixth request look like abuse.
 *
 * WHY IT REFUSES TO RUN WHEN THE FEATURE IS OFF
 * With conversions disabled, expiring quotes would be busywork on a feature
 * nobody can use, and — worse — an operator who disabled the lane during an
 * incident would see a stream of timers still writing to its tables. The
 * `--force` flag exists for the one legitimate exception: cleaning up before
 * re-enabling the lane.
 *
 * WHAT IT PRINTS
 * How many quotes it expired, and in `--dry-run` how many it WOULD expire. A
 * dry run is the only way to answer "is this sweep actually doing anything?"
 * without changing state, and it is the first thing to reach for when the
 * command's log lines look suspiciously quiet.
 */
class ExpireConversionQuotesCommand extends Command
{
    protected $signature = 'conversions:expire-quotes
                            {--dry-run : Report what would be expired without changing anything}
                            {--force : Run even when the conversion feature is disabled}';

    protected $description = 'Expire wallet conversion quotes whose time-to-live has passed';

    public function handle(): int
    {
        if (! (bool) config('conversions.enabled', true) && ! (bool) $this->option('force')) {
            $this->components->warn('Conversions are disabled; nothing to expire. Use --force to sweep anyway.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $candidates = $this->staleCount();

            $this->components->info(sprintf(
                'Dry run: %d quote(s) are past their expiry and would be expired.',
                $candidates,
            ));

            return self::SUCCESS;
        }

        // The sweep itself lives in the job, not in this class. The scheduler and
        // any future workflow dispatch that same job, so there is exactly one
        // implementation of "expire the quotes whose TTL has passed" rather than
        // one per entry point — and a defect in the sweep can only ever exist in
        // one place. The dispatch here is synchronous because an operator running
        // the command wants the count before the prompt comes back; the queued
        // path belongs to the scheduler, where a slow sweep must not hold up
        // every other timer.
        // WHY THE JOB IS RESOLVED AND CALLED RATHER THAN DISPATCHED THROUGH THE
        // QUEUE: `dispatch_sync()` hands the job to the queue plumbing, which
        // returns the queue's own status (0) and discards the count the sweep
        // produced. An operator then reads "No quotes were past their expiry"
        // immediately after quotes were expired — the sweep works and the report
        // lies about it, which is worse than a sweep that fails loudly. Calling
        // the job's handle through the container keeps the single implementation
        // and lets the command report what actually happened.
        $expired = (int) $this->laravel->call([new ExpireConversionQuotesJob, 'handle']);

        if ($expired === 0) {
            $this->components->info('No quotes were past their expiry.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Expired %d quote(s).', $expired));

        // The member's open-quote list is derived from the rows, so nothing else
        // needs invalidating; this line exists so the operator can confirm the
        // sweep ran against the lane that is actually in use.
        $this->components->twoColumnDetail('Open quotes remaining', (string) $this->openCount());

        return self::SUCCESS;
    }

    private function staleCount(): int
    {
        return (int) WalletConversionQuote::query()
            ->where('status', '=', WalletConversionStatus::Quoted->value)
            ->where('expires_at', '<=', now())
            ->count();
    }

    private function openCount(): int
    {
        return (int) WalletConversionQuote::query()
            ->where('status', '=', WalletConversionStatus::Quoted->value)
            ->count();
    }
}
