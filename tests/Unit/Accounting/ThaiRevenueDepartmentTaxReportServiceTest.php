<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\Models\Payout;
use App\Services\Accounting\ThaiRevenueDepartmentTaxReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThaiRevenueDepartmentTaxReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_accurate_monthly_withholding_tax_summary(): void
    {
        $paidDate = Carbon::create(2026, 10, 15, 14, 0, 0);

        Payout::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'lottery_entry_id' => 'entry-1',
            'user_id' => 'user-winner-1',
            'draw_id' => 'GLO-20261016-01',
            'prize_tier' => 'FIRST_PRIZE',
            'gross_amount' => '6000000.0000',
            'wht_tax_amount' => '30000.0000', // 0.5% tax
            'net_amount' => '5970000.0000',
            'currency' => 'THB',
            'payout_status' => 'COMPLETED',
            'paid_at' => $paidDate,
        ]);

        $reportService = new ThaiRevenueDepartmentTaxReportService();
        $report = $reportService->generateMonthlyTaxReport(2026, 10);

        $this->assertSame('2026-10', $report['tax_period']);
        $this->assertSame(1, $report['total_payout_count']);
        $this->assertSame('6000000.0000', $report['total_gross_payout_thb']);
        $this->assertSame('30000.0000', $report['total_wht_remitted_thb']);
        $this->assertSame('5970000.0000', $report['total_net_payout_thb']);
        $this->assertStringContainsString('6000000.0000', $report['csv_export']);
    }
}
