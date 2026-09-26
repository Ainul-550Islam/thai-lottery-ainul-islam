<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GloL6Sale;
use App\Models\GloN3Sale;
use App\Models\GloResultImport;
use App\Models\GloSalesReconciliation;
use App\Services\Lottery\GloFixtureResultProvider;
use App\Services\Lottery\GloOfficialResultProvider;
use Illuminate\Console\Command;

/**
 * glo:provider-health — reports configured result providers and seat/import
 * counters. Read-only. Honest NOT_CONFIGURED surfaced for the official lane.
 */
class GloProviderHealth extends Command
{
    protected $signature = 'glo:provider-health {--json : JSON output}';
    protected $description = 'Report GLO result-provider configuration and sales-seat health (read-only)';

    public function handle(
        GloFixtureResultProvider $fixture,
        GloOfficialResultProvider $official,
    ): int {
        $mode = (string) config('glo.official_source.mode', 'fixture');
        $base = rtrim((string) config('glo.official_source.base_url', ''), '/');
        $latest = (string) config('glo.official_source.latest_lottery_path', '');
        $result = (string) config('glo.official_source.lottery_result_path', '');

        $health = [
            'command' => 'glo:provider-health',
            'mode' => $mode,
            'providers' => [
                'fixture' => [
                    'configured' => $fixture->isConfigured(),
                    'status' => $fixture->isConfigured() ? 'ready' : 'not_configured',
                ],
                'official' => [
                    'configured' => $official->isConfigured(),
                    'status' => $official->isConfigured() ? 'configured_not_probed' : 'not_configured',
                    'endpoints' => [
                        'base' => $base,
                        'latest' => $base.$latest,
                        'result' => $base.$result,
                    ],
                    'note' => 'Only documented glo.or.th catalog endpoints are ever called.',
                ],
            ],
            'counters' => $this->safeCounters(),
            'conflict_gate' => (string) config('glo.reconciliation.conflict_gate', 'GLON3_SALES_CONFLICT'),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('GLO provider health');
            $this->line('  mode: '.$mode);
            $this->line('  fixture: '.($health['providers']['fixture']['status']));
            $this->line('  official: '.$health['providers']['official']['status']);
            $this->line('  l6_seats='.$health['counters']['l6_seats']
                .' n3_seats='.$health['counters']['n3_seats']
                .' n3_conflicted='.$health['counters']['n3_conflicted']
                .' imports='.$health['counters']['imports_total']
                .' not_configured='.$health['counters']['imports_not_configured']
                .' reconciliations='.$health['counters']['reconciliations']);
        }

        return self::SUCCESS;
    }

    /**
     * Counters are best-effort: when the default connection is unavailable
     * (e.g. MySQL not running in a sandbox), report unavailable rather than
     * crash — provider config health must not depend on the DB.
     *
     * @return array<string, int|string>
     */
    private function safeCounters(): array
    {
        try {
            return [
                'l6_seats' => GloL6Sale::query()->count(),
                'n3_seats' => GloN3Sale::query()->count(),
                'n3_conflicted' => GloN3Sale::query()->where('seat_state', 'conflicted')->count(),
                'imports_total' => GloResultImport::query()->count(),
                'imports_imported' => GloResultImport::query()->where('status', 'imported')->count(),
                'imports_not_configured' => GloResultImport::query()->where('status', 'not_configured')->count(),
                'reconciliations' => GloSalesReconciliation::query()->count(),
                'reconciliations_conflicted' => GloSalesReconciliation::query()->where('status', 'conflicted')->count(),
            ];
        } catch (\Throwable $e) {
            return [
                'l6_seats' => 'unavailable',
                'n3_seats' => 'unavailable',
                'n3_conflicted' => 'unavailable',
                'imports_total' => 'unavailable',
                'imports_imported' => 'unavailable',
                'imports_not_configured' => 'unavailable',
                'reconciliations' => 'unavailable',
                'reconciliations_conflicted' => 'unavailable',
                'db_error' => $e->getMessage(),
            ];
        }
    }
}
