<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Payout;
use Carbon\Carbon;

class ThaiRevenueDepartmentTaxReportService
{
    /**
     * Generate Monthly Withholding Tax (WHT) Report for Thai Revenue Department.
     *
     * @return array{
     *     tax_period: string,
     *     total_payout_count: int,
     *     total_gross_payout_thb: string,
     *     total_wht_remitted_thb: string,
     *     total_net_payout_thb: string,
     *     csv_export: string
     * }
     */
    public function generateMonthlyTaxReport(int $year, int $month): array
    {
        $startDate = Carbon::create($year, $month, 1, 0, 0, 0)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $payouts = Payout::where('payout_status', 'COMPLETED')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->get();

        $totalGross = '0.0000';
        $totalWht = '0.0000';
        $totalNet = '0.0000';

        $csvRows = [];
        $csvRows[] = 'TAX_PERIOD,PAYOUT_ID,USER_ID,PRIZE_TIER,GROSS_AMOUNT,WHT_TAX_0.5PCT,NET_AMOUNT,PAID_AT';

        $taxPeriod = $startDate->format('Y-m');

        foreach ($payouts as $payout) {
            $grossExact = bcadd((string) $payout->gross_amount, '0', 4);
            $whtExact = bcadd((string) $payout->wht_tax_amount, '0', 4);
            $netExact = bcadd((string) $payout->net_amount, '0', 4);

            $totalGross = bcadd($totalGross, $grossExact, 4);
            $totalWht = bcadd($totalWht, $whtExact, 4);
            $totalNet = bcadd($totalNet, $netExact, 4);

            $csvRows[] = sprintf(
                '%s,%s,%s,%s,%s,%s,%s,%s',
                $taxPeriod,
                $payout->id,
                $payout->user_id,
                $payout->prize_tier,
                $grossExact,
                $whtExact,
                $netExact,
                $payout->paid_at?->toIso8601String() ?? ''
            );
        }

        return [
            'tax_period' => $taxPeriod,
            'total_payout_count' => $payouts->count(),
            'total_gross_payout_thb' => $totalGross,
            'total_wht_remitted_thb' => $totalWht,
            'total_net_payout_thb' => $totalNet,
            'csv_export' => implode("\n", $csvRows),
        ];
    }
}
