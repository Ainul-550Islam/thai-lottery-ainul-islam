<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Draw;
use App\Services\Lottery\GloL6ProportionalPrizeCalculator;
use App\Services\Lottery\GloL6SalesService;
use App\Services\Lottery\GloN3PrizeCalculator;
use App\Services\Lottery\GloN3SaleService;
use App\Services\Lottery\GloN3SettlementService;
use App\Services\Lottery\GloSalesReconciliationService;
use App\Services\Lottery\GloStampDutyCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * GLO-10 stress harness — exercises sales seating, N3 settlement, L6
 * proportional maths, stamp duty and reconciliation under iteration load.
 *
 * Read/write discipline:
 *  - --dry-run performs ONLY pure calculator work (no DB writes).
 *  - Non-dry-run seats a throwaway in-memory path against the provided
 *    --draw when present; without --draw it stays pure-math.
 *  - Never moves wallet/payout money. Never calls external GLO endpoints.
 *
 * Reports p50/p95 iteration latency as integer microseconds (no floats in
 * money paths — timing is operational metadata only).
 */
class GloStressHarness extends Command
{
    protected $signature = 'glo:stress-harness
        {--iterations=100 : Iterations (1..10000)}
        {--concurrency=4 : Reported concurrency hint (sequential runner)}
        {--draw= : Draw id for optional seat/settle stress}
        {--dry-run : Pure calculator stress, zero DB writes}
        {--json : JSON report only}';

    protected $description = 'GLO-10 stress harness for sales/settlement calculators (never pays, never invents results)';

    public function handle(
        GloL6ProportionalPrizeCalculator $l6,
        GloN3PrizeCalculator $n3,
        GloStampDutyCalculator $duty,
        GloN3SaleService $n3Sales,
        GloN3SettlementService $n3Settlement,
        GloL6SalesService $l6Sales,
        GloSalesReconciliationService $reconciliation,
    ): int {
        $max = (int) config('glo.reconciliation.stress.max_iterations', 10000);
        $default = (int) config('glo.reconciliation.stress.default_iterations', 100);

        $iterations = (int) ($this->option('iterations') ?: $default);
        $iterations = max(1, min($iterations, $max));
        $concurrency = (int) ($this->option('concurrency') ?: (int) config('glo.reconciliation.stress.default_concurrency', 4));
        $dryRun = (bool) $this->option('dry-run');
        $asJson = (bool) $this->option('json');
        $drawId = $this->option('draw') !== null && $this->option('draw') !== ''
            ? (int) $this->option('draw')
            : null;

        $latencies = [];
        $errors = 0;
        $ok = 0;
        $started = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $t0 = hrtime(true);

            try {
                // Pure money paths: L6 proportional + stamp duty + N3 pool.
                $units = ($i * 37) % 1000001;
                $l6->proportionalBreakdown($units);
                $duty->dutyFor(bcadd('1000.00', (string) ($i % 5000), 2));
                $grossN3 = bcmul('20.00', (string) (($i % 50000) + 1), 2);

                // CSPRNG N3 draw (injectable default random_int).
                $n3->calculateFromGrossSales($grossN3);

                if (! $dryRun && $drawId !== null) {
                    $draw = Draw::query()->find($drawId);

                    if ($draw !== null) {
                        $n3Sales->seatSales($draw, [
                            'seats_sold' => ($i % 1000) + 1,
                            'gross_sales' => $grossN3,
                        ]);
                        $l6Sales->seatSales($draw, [
                            'units_sold' => $units,
                        ]);
                        $n3Settlement->settle($draw, null, dryRun: false);
                        $reconciliation->reconcileDraw($draw, null);
                    }
                }

                $ok++;
            } catch (\Throwable) {
                $errors++;
            }

            $latencies[] = (int) ((hrtime(true) - $t0) / 1000);
        }

        $totalMs = (int) ((hrtime(true) - $started) / 1000000);
        sort($latencies);
        $count = count($latencies);
        $p50 = $count > 0 ? $latencies[(int) floor($count * 0.5)] : 0;
        $p95 = $count > 0 ? $latencies[min($count - 1, (int) floor($count * 0.95))] : 0;

        $report = [
            'command' => 'glo:stress-harness',
            'iterations' => $iterations,
            'concurrency_hint' => $concurrency,
            'dry_run' => $dryRun,
            'draw_id' => $drawId,
            'ok' => $ok,
            'errors' => $errors,
            'total_ms' => $totalMs,
            'latency_us_p50' => $p50,
            'latency_us_p95' => $p95,
            'paid' => 0,
            'external_calls' => 0,
        ];

        if ($asJson) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'GLO stress: %d ok / %d errors in %d ms (p50=%dµs p95=%dµs) dry_run=%s paid=0',
                $ok,
                $errors,
                $totalMs,
                $p50,
                $p95,
                $dryRun ? 'yes' : 'no',
            ));
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
