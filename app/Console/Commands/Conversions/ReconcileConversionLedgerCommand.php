<?php

declare(strict_types=1);

namespace App\Console\Commands\Conversions;

use App\Jobs\Wallet\ReconcileConversionLedgerJob;
use Illuminate\Console\Command;

/**
 * Operator wrapper around the reconciliation job.
 *
 * WHY THE COMMAND DISPATCHES THE JOB SYNCHRONOUSLY BY DEFAULT
 * The logic lives in the job because the schedule and any future workflow need
 * it on a queue; the command is what a human types, and a human typing
 * `conversions:reconcile` wants the answer in front of them, not a job id. So
 * the default is a synchronous dispatch, and `--queue` hands it to the worker
 * for the case where the window is large and the operator is happy to read the
 * result from the log.
 *
 * THE EXIT CODE IS THE POINT
 * A reconciliation that only prints is a reconciliation nobody acts on. This
 * command exits non-zero when a mismatch is found, so a cron wrapper, a CI job
 * or a monitoring probe can page on it without parsing prose. Balanced and
 * pending never fail: a movement still settling is not an error.
 */
class ReconcileConversionLedgerCommand extends Command
{
    protected $signature = 'conversions:reconcile
                            {--lookback-hours=48 : How far back to examine completed conversions}
                            {--limit=1000 : Maximum conversions to examine in one pass}
                            {--queue : Dispatch the work to the reconciliation queue instead of running it now}
                            {--json : Emit the report as JSON}';

    protected $description = 'Compare completed wallet conversions against their ledger postings';

    public function handle(): int
    {
        $lookback = max(1, (int) $this->option('lookback-hours'));
        $limit = max(1, (int) $this->option('limit'));

        $job = new ReconcileConversionLedgerJob($lookback, $limit);

        if ((bool) $this->option('queue')) {
            dispatch($job);

            $this->components->info(sprintf(
                'Reconciliation queued for the last %d hour(s), up to %d conversion(s).',
                $lookback,
                $limit,
            ));

            return self::SUCCESS;
        }

        // The job is called through the container rather than via
        // `dispatch_sync()`, which returns the queue's status code instead of the
        // job's report: reading a report array off an integer is an ErrorException
        // in the middle of the night, which is exactly when this command runs.
        /** @var array<string, mixed> $report */
        $report = $this->laravel->call([$job, 'handle']);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode($report);
        }

        $this->components->twoColumnDetail('Conversions examined', (string) $report['checked']);
        $this->components->twoColumnDetail('Balanced', (string) $report['balanced']);
        $this->components->twoColumnDetail('Still settling', (string) $report['pending']);
        $this->components->twoColumnDetail('Mismatched', (string) $report['mismatched']);

        if ($report['mismatched'] > 0) {
            $this->newLine();
            $this->components->error(sprintf(
                '%d conversion(s) disagree with the ledger. Nothing has been changed: investigate, then correct through a reversal or a manual journal.',
                $report['mismatched'],
            ));

            $this->table(
                ['Reference', 'Amount in', 'Fee', 'Source delta', 'Destination delta', 'Fee posted'],
                array_map(static fn (array $row): array => [
                    $row['reference'],
                    $row['amount_in'],
                    $row['fee_amount'],
                    $row['source_delta'],
                    $row['destination_delta'],
                    $row['fee_posted'],
                ], $report['mismatches']),
            );

            return self::FAILURE;
        }

        $this->components->info('Every completed conversion in the window reconciles to the ledger.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function exitCode(array $report): int
    {
        return ((int) ($report['mismatched'] ?? 0)) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
