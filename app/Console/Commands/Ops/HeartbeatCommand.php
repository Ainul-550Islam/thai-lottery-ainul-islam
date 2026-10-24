<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use Illuminate\Console\Command;

/**
 * `ops:heartbeat` — writes a timestamp the `ops:health` probe can read.
 *
 * THE FAILURE MODE THIS ADDRESSES
 * -------------------------------
 * `schedule:work` and `schedule:run` are silent when they stop. If cron is
 * removed, the container is rescheduled without the scheduler, or
 * `schedule:work` dies, nothing fails: there is no error, no failed job and no
 * log line, because no job ever started. Every HTTP health probe keeps
 * answering 200 while draw settlement, prize expiry, reconciliation and
 * payouts quietly stop happening.
 *
 * A scheduled heartbeat turns "the scheduler is running" into a fact with a
 * timestamp on it, so the absence becomes measurable rather than assumed.
 *
 * The file is written with a plain timestamp (not JSON) because it is read by
 * monitoring shells as well as by PHP, and it must cost nothing to parse when
 * the host is already in trouble.
 */
class HeartbeatCommand extends Command
{
    protected $signature = 'ops:heartbeat
        {--show : Print the current heartbeat age instead of writing one}';

    protected $description = 'Record (or report) the scheduler heartbeat used by ops:health.';

    public function handle(): int
    {
        $path = storage_path('framework/schedule-heartbeat');
        $directory = dirname($path);

        if ($this->option('show')) {
            if (! is_file($path)) {
                $this->components->warn('No scheduler heartbeat has ever been recorded.');

                return self::FAILURE;
            }

            $recorded = (int) trim((string) file_get_contents($path));
            $age = max(0, time() - $recorded);

            $this->line(sprintf(
                'Heartbeat %s (%d seconds ago, %s).',
                gmdate('c', $recorded),
                $age,
                $age > 600 ? 'STALE' : 'fresh'
            ));

            return $age > 600 ? self::FAILURE : self::SUCCESS;
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0770, true) && ! is_dir($directory)) {
            $this->components->error('Unable to create storage/framework for the heartbeat file.');

            return self::FAILURE;
        }

        // Write atomically: a monitor must never read a half-written file and
        // conclude the scheduler has stopped.
        $temporary = $path.'.tmp';

        if (@file_put_contents($temporary, (string) time()) === false || ! @rename($temporary, $path)) {
            @unlink($temporary);
            $this->components->error('Unable to write the scheduler heartbeat file.');

            return self::FAILURE;
        }

        @chmod($path, 0660);

        if ($this->output->isVerbose()) {
            $this->components->info('Scheduler heartbeat recorded at '.gmdate('c').'.');
        }

        return self::SUCCESS;
    }
}
