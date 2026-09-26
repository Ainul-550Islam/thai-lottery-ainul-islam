<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\GloSourceState;
use App\Services\Lottery\GloSalesPointService;
use Illuminate\Console\Command;

/**
 * glo:sync-sales-points — official sales-point feed sync.
 *
 * There is NO authorized public GLO sales-point feed configured for this
 * project, so the command reports NOT_CONFIGURED and writes nothing unless
 * a future official_sync.mode is set and an endpoint exists. Fixture data
 * is managed separately via operator/admin paths (source=fixture).
 */
class GloSyncSalesPoints extends Command
{
    protected $signature = 'glo:sync-sales-points {--json : JSON output}';
    protected $description = 'Sync sales points from an official feed (honest NOT_CONFIGURED when no authorized source)';

    public function handle(GloSalesPointService $salesPoints): int
    {
        $status = $salesPoints->officialSyncStatus();

        $report = array_merge($status, [
            'command' => 'glo:sync-sales-points',
            'synced' => 0,
            'mode' => (string) config('glo.sales_points.official_sync.mode', 'not_configured'),
            'endpoint' => config('glo.sales_points.official_sync.endpoint'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->warn(sprintf(
                'sync-sales-points: %s — %s (synced=0)',
                $report['status'],
                $report['detail'],
            ));
        }

        // NOT_CONFIGURED is a successful honest outcome (exit 0).
        return self::SUCCESS;
    }
}
