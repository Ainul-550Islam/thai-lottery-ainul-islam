<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContactMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Retention sweep for contact messages (PROMPT 10, file 22).
 *
 * IT CANNOT DELETE AN UNRESOLVED MESSAGE. The query is filtered to the
 * statuses configuration marks purgeable - RESOLVED, ARCHIVED, SPAM by
 * default. A message nobody has dealt with is never removed on a schedule: a
 * support request disappearing because it aged is a worse failure than a
 * table that grows.
 *
 * IT REPORTS COUNTS, NEVER CONTENT. No name, address, subject or body is
 * printed. A retention job's output ends up in cron mail and CI logs, which
 * are the last places a visitor's message should appear.
 *
 * IT IS IDEMPOTENT. Running it twice deletes nothing the second time, because
 * the rows it would have matched are gone. --dry-run reports the same counts
 * without writing.
 *
 * The retention period is a configured number. Nothing here asserts a legal or
 * statutory period.
 */
class PurgeContactMessages extends Command
{
    /**
     * @var string
     */
    protected $signature = 'contact:purge
        {--dry-run : Count what would be removed and write nothing}';

    /**
     * @var string
     */
    protected $description = 'Delete contact messages that are resolved, archived or spam and older than the configured retention period.';

    public function handle(): int
    {
        $days = (int) config('contact.retention.days', 365);

        if ($days < 1) {
            $this->warn('Retention is disabled (contact.retention.days is below 1). Nothing was removed.');

            return self::SUCCESS;
        }

        $statuses = config('contact.retention.purgeable_statuses');
        $statuses = is_array($statuses) ? array_values(array_filter($statuses, 'is_string')) : [];

        if ($statuses === []) {
            $this->warn('No purgeable statuses are configured. Nothing was removed.');

            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subDays($days);

        $query = ContactMessage::query()
            ->whereIn('status', $statuses)
            ->where('created_at', '<', $cutoff);

        $count = (int) $query->clone()->count();
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->line(sprintf('Dry run: %d message(s) would be removed.', $count));

            return self::SUCCESS;
        }

        // Delivery attempts go with the message through the foreign key's
        // cascade, so the history cannot outlive the record it describes.
        $deleted = $count === 0 ? 0 : (int) $query->delete();

        $this->line(sprintf('Removed %d message(s) older than %d day(s).', $deleted, $days));
        $this->line(sprintf('Retained statuses: %s.', implode(', ', $statuses)));

        return self::SUCCESS;
    }
}
