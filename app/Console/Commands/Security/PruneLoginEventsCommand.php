<?php

declare(strict_types=1);

namespace App\Console\Commands\Security;

use App\Models\LoginEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class PruneLoginEventsCommand extends Command
{
    protected $signature = 'security:prune-login-events
        {--days= : Override configured retention days}
        {--dry-run : Count rows eligible for pruning without deleting}';

    protected $description = 'Prune login_events older than the configured retention window.';

    public function handle(): int
    {
        $daysOption = $this->option('days');
        $retentionDays = is_string($daysOption) && $daysOption !== ''
            ? max(1, (int) $daysOption)
            : max(1, (int) config('rewards.login_history.retention_days', 180));

        $cutoff = Carbon::now()->subDays($retentionDays);
        $query = LoginEvent::query()->where('occurred_at', '<=', $cutoff);

        if ((bool) $this->option('dry-run')) {
            $count = $query->count();
            $this->info(sprintf('[DRY-RUN] %d login_events older than %d days (%s) would be pruned.', $count, $retentionDays, $cutoff->toIso8601String()));

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info(sprintf('Pruned %d login_events older than %d days (%s).', (int) $deleted, $retentionDays, $cutoff->toIso8601String()));

        return self::SUCCESS;
    }
}
