<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WebhookReplayGuard;
use Illuminate\Console\Command;

/**
 * Retention sweep for the durable webhook replay guard.
 *
 * ============================================================================
 * WHY THIS IS A SEPARATE, SCHEDULED, BOUNDED DELETE
 * ============================================================================
 * webhook_replay_guards grows with delivered traffic — one row per distinct
 * signed payload, which on a busy gateway is every deposit and withdrawal
 * callback a provider sends. It is a small row, but it is unbounded, and an
 * unbounded table behind a UNIQUE index eventually costs insert latency on the
 * hot payment path. That is the failure this command exists to prevent.
 *
 * ============================================================================
 * WHAT IT DELETES, AND WHAT IT MUST NOT
 * ============================================================================
 * Only rows whose `expires_at` has passed. `expires_at` is written at claim time
 * as now + payment.webhook.replay_guard_retention_days, so deletion is an
 * INDEXED RANGE DELETE rather than a full scan of a table that keeps growing
 * while the sweep runs.
 *
 * Deliberately NOT deleted:
 *   - rows with a NULL `expires_at` (a row written by an older schema, or by an
 *     operator by hand — we did not set a horizon for it, so we do not guess one);
 *   - rows whose horizon is still in the future, however old they look.
 *
 * Deleting a live row is not a cosmetic mistake: it re-opens the replay window
 * for that exact payload, which is the control this table exists to provide. The
 * cutoff therefore comes from the row's own recorded horizon and never from a
 * recomputed "now minus N days", which would drift if the retention setting were
 * ever changed.
 *
 * ============================================================================
 * IT IS SAFE TO RUN TWICE, AND SAFE TO RUN WHILE TRAFFIC FLOWS
 * ============================================================================
 * The delete is idempotent (the second run matches nothing) and touches only
 * rows no delivery can still be replaying. --dry-run reports the same counts and
 * writes nothing.
 */
class PurgeWebhookReplayGuards extends Command
{
    /**
     * @var string
     */
    protected $signature = 'payment:purge-webhook-replay-guards
        {--dry-run : Count what would be removed and write nothing}
        {--chunk=1000 : How many rows to delete per statement}';

    /**
     * @var string
     */
    protected $description = 'Delete webhook replay-guard rows whose retention horizon has passed.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $chunk = (int) $this->option('chunk');
        $chunk = $chunk < 1 ? 1000 : $chunk;

        // The horizon is the ROW'S OWN expires_at, never a recomputed window.
        // See the class docblock: a recomputed cutoff would re-open the replay
        // window for anything the operator changed the retention setting for.
        $query = WebhookReplayGuard::query()->expired();

        $count = (int) $query->clone()->count();

        if ($count === 0) {
            $this->line('No webhook replay-guard rows have passed their retention horizon.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line(sprintf('Dry run: %d replay-guard row(s) would be removed.', $count));

            return self::SUCCESS;
        }

        // Chunked, so a first sweep on a table that has never been pruned does
        // not hold a long lock across the whole range and stall webhook inserts
        // behind it.
        //
        // SELECT-then-DELETE-by-id rather than DELETE ... LIMIT. LIMIT on a
        // DELETE statement is valid MySQL and INVALID on PostgreSQL and SQLite
        // without a build option, and Laravel's grammar differs by driver: on
        // one driver a limit in a delete is honoured, on another it is silently
        // DROPPED, which turns a bounded sweep into a full-table delete that
        // holds locks the whole way. Selecting ids first behaves identically on
        // all three.
        $deleted = 0;

        while (true) {
            $ids = WebhookReplayGuard::query()
                ->expired()
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += (int) WebhookReplayGuard::query()->whereIn('id', $ids->all())->delete();
        }

        $retentionDays = (int) config('payment.webhook.replay_guard_retention_days', 30);

        $this->line(sprintf(
            'Removed %d replay-guard row(s); retention is %d day(s).',
            $deleted,
            $retentionDays,
        ));

        return self::SUCCESS;
    }
}
