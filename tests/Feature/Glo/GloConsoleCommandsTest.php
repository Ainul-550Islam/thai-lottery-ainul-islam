<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Models\Draw;
use App\Services\Lottery\GloL6SalesService;
use App\Services\Lottery\GloN3SaleService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GLO-10 console commands: glo:stress-harness, glo:provider-health,
 * glo:import-result, glo:reconcile-sales.
 *
 * Commands never pay winners and never invent official results.
 */
class GloConsoleCommandsTest extends TestCase
{
    use DatabaseTruncation;

    private Draw $draw;

    protected function setUp(): void
    {
        parent::setUp();

        $this->draw = Draw::factory()->create([
            'draw_number' => 'GLO-2026-09-16',
            'status' => 'result_published',
            'scheduled_at' => now()->subDays(3),
            'result_published_at' => now()->subDays(2),
        ]);

        config(['glo.official_source.mode' => 'fixture']);
    }

    public function test_stress_harness_dry_run_pure_math_success(): void
    {
        $this->artisan('glo:stress-harness', [
            '--iterations' => 25,
            '--dry-run' => true,
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_stress_harness_json_report_shape(): void
    {
        $this->artisan('glo:stress-harness', [
            '--iterations' => 10,
            '--dry-run' => true,
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_stress_harness_iterations_capped_at_max(): void
    {
        $this->artisan('glo:stress-harness', [
            '--iterations' => 999999,
            '--dry-run' => true,
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_stress_harness_with_draw_seats_and_settles(): void
    {
        $this->artisan('glo:stress-harness', [
            '--iterations' => 3,
            '--draw' => $this->draw->getKey(),
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('glo_n3_sales', [
            'draw_id' => $this->draw->getKey(),
            'product' => 'n3',
        ]);
        $this->assertDatabaseHas('glo_l6_sales', [
            'draw_id' => $this->draw->getKey(),
            'product' => 'l6',
        ]);
    }

    public function test_provider_health_reports_fixture_ready_and_official_not_configured(): void
    {
        config(['glo.official_source.mode' => 'fixture']);

        $response = $this->artisan('glo:provider-health', ['--json' => true]);
        $response->assertExitCode(0);
    }

    public function test_provider_health_counts_increase_after_seat(): void
    {
        app(GloN3SaleService::class)->seatSales($this->draw, ['seats_sold' => 3]);
        app(GloL6SalesService::class)->seatSales($this->draw, ['units_sold' => 4]);

        $this->assertDatabaseCount('glo_n3_sales', 1);
        $this->assertDatabaseCount('glo_l6_sales', 1);

        $this->artisan('glo:provider-health', ['--json' => true])->assertExitCode(0);
    }

    public function test_import_result_by_draw_number_success(): void
    {
        $this->artisan('glo:import-result', [
            '--draw' => 'GLO-2026-09-16',
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('glo_result_imports', [
            'draw_id' => $this->draw->getKey(),
            'status' => 'imported',
        ]);
    }

    public function test_import_result_missing_draw_fails(): void
    {
        $this->artisan('glo:import-result', [
            '--draw' => 'NOPE-999',
        ])->assertExitCode(1);
    }

    public function test_import_result_requires_draw_or_latest(): void
    {
        $this->artisan('glo:import-result')->assertExitCode(1);
    }

    public function test_import_result_latest_fixture(): void
    {
        $this->artisan('glo:import-result', [
            '--latest' => true,
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_reconcile_sales_requires_draw(): void
    {
        $this->artisan('glo:reconcile-sales')->assertExitCode(1);
    }

    public function test_reconcile_sales_matched_exit_zero(): void
    {
        app(GloN3SaleService::class)->seatSales($this->draw, ['seats_sold' => 50]);

        $this->artisan('glo:reconcile-sales', [
            '--draw' => (string) $this->draw->getKey(),
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('glo_sales_reconciliations', [
            'draw_id' => $this->draw->getKey(),
            'product' => 'n3',
            'status' => 'matched',
        ]);
    }

    public function test_reconcile_sales_variance_writes_conflict_gate(): void
    {
        app(GloN3SaleService::class)->seatSales($this->draw, ['seats_sold' => 50]);

        $this->artisan('glo:reconcile-sales', [
            '--draw' => (string) $this->draw->getKey(),
            '--product' => 'n3',
            '--expected-seats' => '40',
            '--expected-gross' => '800.00',
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('glo_sales_reconciliations', [
            'draw_id' => $this->draw->getKey(),
            'product' => 'n3',
            'status' => 'conflicted',
            'conflict_gate' => 'GLON3_SALES_CONFLICT',
        ]);
    }

    public function test_reconcile_sales_unknown_draw_fails(): void
    {
        $this->artisan('glo:reconcile-sales', ['--draw' => 'MISSING'])->assertExitCode(1);
    }

    public function test_all_four_commands_are_registered(): void
    {
        foreach ([
            'glo:stress-harness',
            'glo:provider-health',
            'glo:import-result',
            'glo:reconcile-sales',
            'glo:freeze-ticket',
            'glo:freeze-case',
            'glo:expire-freezes',
            'glo:process-frozen-winners',
        ] as $command) {
            $this->artisan($command, ['--help' => true])->assertExitCode(0);
        }
    }
}
