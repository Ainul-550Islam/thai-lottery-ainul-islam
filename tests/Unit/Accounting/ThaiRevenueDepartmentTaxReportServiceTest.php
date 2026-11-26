<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\Enums\PayoutDocumentStatus;
use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Models\PayoutDocument;
use App\Models\User;
use App\Services\Accounting\ThaiRevenueDepartmentTaxReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The report is a legal return, so the assertions are about the arithmetic and the cut-off,
 * not about shape: what was withheld is reported as recorded, the period boundary is the
 * completion date, and a cancelled statement is not a remittance.
 *
 * The previous version of this test wrote a `payouts` row with columns the schema does not
 * have (`payout_number`, `gross_amount`, `wht_tax_amount`, …). It failed with
 * MassAssignmentException before the service was ever reached — which is why the service's own
 * broken column names survived: the test never got far enough to notice them.
 */
class ThaiRevenueDepartmentTaxReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sums_the_statements_of_payouts_completed_in_the_period(): void
    {
        $paidAt = Carbon::create(2026, 10, 15, 14, 0, 0);

        $first = $this->completedPayout($paidAt, '6000000.00');
        $this->statementFor($first, '6000000.00', '30000.00', '5970000.00');

        $second = $this->completedPayout($paidAt->copy()->addDay(), '7000.00');
        $this->statementFor($second, '7000.00', '35.00', '6965.00');

        $report = (new ThaiRevenueDepartmentTaxReportService)->generateMonthlyTaxReport(2026, 10);

        $this->assertSame('2026-10', $report['tax_period']);
        $this->assertSame(2, $report['total_payout_count']);
        $this->assertSame(0, $report['undocumented_payout_count']);
        $this->assertSame('6007000.00', $report['total_gross_payout_thb']);
        $this->assertSame('30035.00', $report['total_wht_remitted_thb']);
        $this->assertSame('5976965.00', $report['total_net_payout_thb']);
        // One CSV line per payout, each carrying its own recorded gross/tax/net.
        $lines = explode("\n", $report['csv_export']);
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('6000000.00,30000.00,5970000.00', $lines[1]);
        $this->assertStringContainsString($first->reference_number, $lines[1]);
        $this->assertStringContainsString('7000.00,35.00,6965.00', $lines[2]);
        $this->assertStringContainsString($second->reference_number, $lines[2]);
    }

    public function test_it_uses_the_completion_date_not_the_creation_date_as_the_cut_off(): void
    {
        // Created in September, completed in October: October's return, not September's.
        $payout = Payout::factory()->create([
            'status' => PayoutStatus::Completed,
            'processed_at' => Carbon::create(2026, 10, 1, 0, 5, 0),
            'created_at' => Carbon::create(2026, 9, 30, 23, 55, 0),
            'amount' => '1000.00',
        ]);
        $this->statementFor($payout, '1000.00', '5.00', '995.00');

        $service = new ThaiRevenueDepartmentTaxReportService;

        $this->assertSame(1, $service->generateMonthlyTaxReport(2026, 10)['total_payout_count']);
        $this->assertSame(0, $service->generateMonthlyTaxReport(2026, 9)['total_payout_count']);
    }

    public function test_a_payout_that_has_not_completed_is_not_in_the_return(): void
    {
        Payout::factory()->create(['amount' => '500.00']);                 // Pending by default
        Payout::factory()->processing()->create(['amount' => '500.00']);

        $report = (new ThaiRevenueDepartmentTaxReportService)
            ->generateMonthlyTaxReport((int) now()->format('Y'), (int) now()->format('n'));

        $this->assertSame(0, $report['total_payout_count']);
        $this->assertSame('0.00', $report['total_gross_payout_thb']);
        $this->assertSame(
            'TAX_PERIOD,PAYOUT_ID,USER_ID,PAYOUT_REFERENCE,GROSS_AMOUNT,WHT_TAX,NET_AMOUNT,PAID_AT',
            $report['csv_export'],
        );
    }

    public function test_a_cancelled_statement_is_not_a_remittance(): void
    {
        $payout = $this->completedPayout(Carbon::create(2026, 10, 5, 9, 0, 0), '2000.00');
        PayoutDocument::factory()->cancelled()->create([
            'payout_id' => $payout->getKey(),
            'claimant_user_id' => $payout->user_id,
            'gross_amount' => '2000.00',
            'tax_amount' => '10.00',
            'net_amount' => '1990.00',
        ]);

        $report = (new ThaiRevenueDepartmentTaxReportService)->generateMonthlyTaxReport(2026, 10);

        // The payout still completed, so its gross is reported — but no tax is claimed against
        // a statement that was cancelled, and the gap is counted rather than assumed away.
        $this->assertSame(1, $report['total_payout_count']);
        $this->assertSame(1, $report['undocumented_payout_count']);
        $this->assertSame('2000.00', $report['total_gross_payout_thb']);
        $this->assertSame('0.00', $report['total_wht_remitted_thb']);
    }

    public function test_totals_are_exact_at_the_columns_own_scale(): void
    {
        // 0.01 + 0.02 is 0.03 in bcmath and 0.030000000000000004 in binary floating point.
        foreach (['0.01', '0.02'] as $index => $gross) {
            $payout = $this->completedPayout(
                Carbon::create(2026, 10, 10 + $index, 12, 0, 0),
                $gross,
            );
            $this->statementFor($payout, $gross, '0.00', $gross);
        }

        $report = (new ThaiRevenueDepartmentTaxReportService)->generateMonthlyTaxReport(2026, 10);

        $this->assertSame('0.03', $report['total_gross_payout_thb']);
        $this->assertSame('0.03', $report['total_net_payout_thb']);
    }

    private function completedPayout(Carbon $processedAt, string $amount): Payout
    {
        return Payout::factory()->create([
            'status' => PayoutStatus::Completed,
            'processed_at' => $processedAt,
            'amount' => $amount,
            'user_id' => User::factory(),
        ]);
    }

    private function statementFor(Payout $payout, string $gross, string $tax, string $net): PayoutDocument
    {
        return PayoutDocument::factory()->create([
            'payout_id' => $payout->getKey(),
            'payout_reference' => $payout->reference_number,
            'claimant_user_id' => $payout->user_id,
            'gross_amount' => $gross,
            'tax_amount' => $tax,
            'net_amount' => $net,
            'status' => PayoutDocumentStatus::Issued,
        ]);
    }
}
