<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Draw;
use App\Services\Lottery\GloResultNotificationService;
use Illuminate\Console\Command;

/**
 * glo:notify-saved-tickets — deterministic post-result notification processing
 * for GLO-17. Idempotent via delivery_key + notifications.message_fingerprint.
 * Never claims push delivery when the provider is NOT_CONFIGURED.
 *
 *   glo:notify-saved-tickets --draw=123
 *   glo:notify-saved-tickets --draw=DRW-… --json
 *   glo:notify-saved-tickets --draw=123 --dry-run
 */
class GloNotifySavedTickets extends Command
{
    protected $signature = 'glo:notify-saved-tickets
        {--draw= : Draw id or draw_number (required)}
        {--dry-run : Count candidates without writing notifications}
        {--json : JSON report}';

    protected $description = 'Notify owners of saved GLO tickets after a verified result (idempotent, honest provider state)';

    public function handle(GloResultNotificationService $notifications): int
    {
        $ref = (string) $this->option('draw');

        if ($ref === '') {
            $this->error('--draw= is required.');

            return self::FAILURE;
        }

        $draw = ctype_digit($ref)
            ? Draw::query()->find((int) $ref)
            : Draw::query()->where('draw_number', $ref)->orWhere('uuid', $ref)->first();

        if ($draw === null) {
            $this->error('Draw not found: '.$ref);

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $report = $notifications->processDraw($draw, $dryRun);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $report['command'] = 'glo:notify-saved-tickets';
        $report['dry_run'] = $dryRun;
        $report['push_provider'] = (string) config('glo.notifications.push_provider', 'not_configured');
        $report['push_delivery_claimed'] = false;

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'notify-saved-tickets draw=%s processed=%d notified=%d replayed=%d skipped=%d failures=%d dry_run=%s',
                $draw->draw_number,
                $report['processed'],
                $report['notified'],
                $report['replayed'],
                $report['skipped'],
                count($report['failures']),
                $dryRun ? 'yes' : 'no',
            ));
        }

        return $report['failures'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
