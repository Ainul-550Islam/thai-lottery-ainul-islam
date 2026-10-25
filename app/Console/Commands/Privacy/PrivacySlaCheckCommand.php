<?php

declare(strict_types=1);

namespace App\Console\Commands\Privacy;

use App\Jobs\SendPrivacySlaRemindersJob;
use App\Models\DataSubjectRequest;
use Illuminate\Console\Command;

/**
 * `privacy:sla-check` — dispatch the daily digest and print what it saw.
 *
 * WHY A COMMAND AND NOT ONLY A JOB. The schedule dispatches the job, but an
 * operator asking "what is overdue right now?" needs an answer without waiting
 * for 08:00, and an operations check needs an exit code. The command therefore
 * dispatches the SAME job (so there is one implementation of "remind") and then
 * prints the two counts.
 *
 * --dry-run reports without dispatching, so a deployment can verify the wiring
 * before a mail exists to send.
 */
final class PrivacySlaCheckCommand extends Command
{
    protected $signature = 'privacy:sla-check {--dry-run : Report counts without dispatching the digest}';

    protected $description = 'Dispatch the privacy-request SLA digest and report overdue and due-soon counts.';

    public function handle(): int
    {
        if (! (bool) config('privacy_requests.enabled', false)) {
            $this->info('privacy requests are not configured; no digest dispatched');

            return self::SUCCESS;
        }

        $overdue = DataSubjectRequest::query()->overdue()->count();
        $dueSoon = DataSubjectRequest::query()->dueSoon()->count();

        $this->info(sprintf('overdue: %d', $overdue));
        $this->info(sprintf('due soon: %d', $dueSoon));

        if ((bool) $this->option('dry-run')) {
            $this->comment('dry run: no digest dispatched');

            return self::SUCCESS;
        }

        $configured = config('privacy_requests.compliance_email');

        if (! is_string($configured) || trim($configured) === '') {
            // The job would send nothing. Saying so here is the honest report;
            // exiting 0 keeps a scheduler from alerting on an unset mailbox that
            // the operator already knows about.
            $this->warn('no compliance address is configured, so no digest will be delivered');

            return self::SUCCESS;
        }

        SendPrivacySlaRemindersJob::dispatch();

        return self::SUCCESS;
    }
}
