<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Console\Commands\Finance\ReconcileFinancialRecordsCommand;
use App\Services\Finance\FinancialReconciliationExportService;
use App\Services\Finance\FinancialReconciliationService;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0-C: reconciliation command contract — --dry-run and bounded --batch;
 * export service is deterministic and never mutates.
 */
final class ReconcileCommandContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function command_exposes_dry_run_and_batch_options(): void
    {
        $reflection = new \ReflectionClass(ReconcileFinancialRecordsCommand::class);
        $signature = (string) $reflection->getProperty('signature')->getValue($reflection->newInstanceWithoutConstructor());

        $this->assertStringContainsString('--dry-run', $signature);
        $this->assertStringContainsString('--batch=', $signature);
        $this->assertStringContainsString('--from=', $signature);
        $this->assertStringContainsString('--to=', $signature);
        $this->assertStringContainsString('--json', $signature);
    }

    #[Test]
    public function dry_run_reports_without_crashing_and_flags_mode(): void
    {
        // Exit 0 = clean books, 1 = critical found and reported. Either is a
        // successful *contract* run; a crash or option-parse failure is not.
        $code = \Illuminate\Support\Facades\Artisan::call('finance:reconcile', [
            '--dry-run' => true,
            '--batch' => '10',
            '--json' => true,
        ]);
        $this->assertContains($code, [0, 1], 'dry-run must report, not crash (got '.$code.')');

        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('dry_run', $out);
        $this->assertStringContainsString('batch_bound', $out);
    }

    #[Test]
    public function batch_is_bounded_by_max_ceiling(): void
    {
        // Over-ceiling values are clamped, not rejected as a crash.
        $code = \Illuminate\Support\Facades\Artisan::call('finance:reconcile', [
            '--dry-run' => true,
            '--batch' => '999999',
            '--json' => true,
        ]);
        $this->assertContains($code, [0, 1], 'clamped batch must report (got '.$code.')');
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('batch_bound', $out);
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded);
        $this->assertLessThanOrEqual(10000, (int) ($decoded['batch_bound'] ?? 0));
    }

    #[Test]
    public function non_numeric_batch_is_rejected(): void
    {
        $this->artisan('finance:reconcile', ['--dry-run' => true, '--batch' => 'abc'])
            ->assertFailed();
    }

    #[Test]
    public function export_service_rows_are_deterministic_and_have_no_pii_columns(): void
    {
        $service = app(FinancialReconciliationExportService::class);

        $a = $service->export(null, null, null, 5);
        $b = $service->export(null, null, null, 5);

        $this->assertSame(
            json_encode($a['transactions'] ?? []),
            json_encode($b['transactions'] ?? []),
        );
        $this->assertSame(
            json_encode($a['ledger_entries'] ?? []),
            json_encode($b['ledger_entries'] ?? []),
        );

        $csv = $service->toCsv($a);
        $this->assertStringContainsString('transaction_id', $csv);
        $this->assertStringNotContainsString('password', strtolower($csv));
        $this->assertStringNotContainsString('token', strtolower($csv));
        $this->assertStringNotContainsString('national_id', strtolower($csv));
        $this->assertStringNotContainsString('file_path', strtolower($csv));
    }

    #[Test]
    public function export_json_payload_shape_is_stable(): void
    {
        $service = app(FinancialReconciliationExportService::class);
        $payload = $service->export();

        foreach (['generated_at', 'row_count', 'truncated', 'transactions', 'ledger_entries', 'reconciliation'] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }
        $this->assertLessThanOrEqual(FinancialReconciliationExportService::MAX_ROWS, $payload['row_count']);
    }

    #[Test]
    public function reconciliation_service_is_read_only_report_not_balance_mutator(): void
    {
        $service = app(FinancialReconciliationService::class);
        $report = $service->reconcile(initiatedBy: 'test-readonly');

        $this->assertObjectHasProperty('status', $report);
        // Report exposes anomaly counts; there is no method that writes balances.
        $this->assertFalse(
            method_exists($service, 'forceBalance'),
            'reconciliation must never offer a force-balance mutator',
        );
        $this->assertFalse(
            method_exists($service, 'fixMismatch'),
            'reconciliation must never offer a fix-mismatch mutator',
        );
    }
}
