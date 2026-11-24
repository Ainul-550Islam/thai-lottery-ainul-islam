<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Models\PayoutDocument;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Monthly withholding-tax (WHT) report for the Thai Revenue Department.
 *
 * This report used to be written against a `payouts` table that does not exist in this
 * schema: it filtered on `payout_status` and `paid_at`, and read `gross_amount`,
 * `wht_tax_amount` and `net_amount` from the payout row. None of those columns exist — the
 * table has `status`, `processed_at` and `amount`, and the *recorded* tax lives on the payout
 * statement (`payout_documents`), which stores gross, tax and net together and enforces
 * `gross = tax + net`. The query therefore failed at the database, not at the assertion: the
 * report could never have been produced, and nothing exercised it because its only caller is a
 * unit test.
 *
 * Two rules shape the rewrite:
 *
 *  - **Report what was withheld, never recompute it.** The tax figure comes from the statement
 *    the payout service wrote. Applying the configured 0.5% rate here would silently disagree
 *    with the ledger the moment a rate, an exemption or a stamp-duty treatment changed.
 *  - **Money is exact.** Every total is bcmath at the column's own scale (2), so the arithmetic
 *    in the CSV is the arithmetic in the database, not a float rendering of it.
 */
final class ThaiRevenueDepartmentTaxReportService
{
    /** The scale of every money column this report reads (`decimal(20,2)`). */
    private const SCALE = 2;

    /**
     * @return array{
     *     tax_period: string,
     *     total_payout_count: int,
     *     undocumented_payout_count: int,
     *     total_gross_payout_thb: string,
     *     total_wht_remitted_thb: string,
     *     total_net_payout_thb: string,
     *     csv_export: string
     * }
     */
    public function generateMonthlyTaxReport(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1, 0, 0, 0)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $taxPeriod = $start->format('Y-m');

        $payouts = Payout::query()
            ->where('status', PayoutStatus::Completed->value)
            ->whereBetween('processed_at', [$start, $end])
            ->orderBy('id')
            ->get(['id', 'user_id', 'reference_number', 'amount', 'processed_at']);

        $documents = $this->statementsFor($payouts);

        $totalGross = $this->exact(0);
        $totalWht = $this->exact(0);
        $totalNet = $this->exact(0);
        $undocumented = 0;

        $csvRows = ['TAX_PERIOD,PAYOUT_ID,USER_ID,PAYOUT_REFERENCE,GROSS_AMOUNT,WHT_TAX,NET_AMOUNT,PAID_AT'];

        foreach ($payouts as $payout) {
            $statement = $documents->get($payout->getKey());

            if ($statement instanceof PayoutDocument) {
                $gross = $this->exact($statement->gross_amount);
                $wht = $this->exact($statement->tax_amount);
                $net = $this->exact($statement->net_amount);
            } else {
                // A completed payout with no statement has had no tax withheld as far as the
                // record shows. It is counted separately rather than taxed by assumption, so
                // the gap is visible to whoever reconciles the return.
                $undocumented++;
                $gross = $this->exact($payout->amount);
                $wht = $this->exact(0);
                $net = $gross;
            }

            $totalGross = bcadd($totalGross, $gross, self::SCALE);
            $totalWht = bcadd($totalWht, $wht, self::SCALE);
            $totalNet = bcadd($totalNet, $net, self::SCALE);

            $csvRows[] = implode(',', array_map($this->csvField(...), [
                $taxPeriod,
                (string) $payout->getKey(),
                (string) $payout->user_id,
                (string) $payout->reference_number,
                $gross,
                $wht,
                $net,
                $payout->processed_at?->toIso8601String() ?? '',
            ]));
        }

        return [
            'tax_period' => $taxPeriod,
            'total_payout_count' => $payouts->count(),
            'undocumented_payout_count' => $undocumented,
            'total_gross_payout_thb' => $totalGross,
            'total_wht_remitted_thb' => $totalWht,
            'total_net_payout_thb' => $totalNet,
            'csv_export' => implode("\n", $csvRows),
        ];
    }

    /**
     * The active statement for each payout, keyed by payout id.
     *
     * A cancelled statement is not a remittance, and a reissued payout carries exactly one
     * active statement — the highest id wins, which is the last one written.
     *
     * @param  Collection<int, Payout>  $payouts
     * @return Collection<int, PayoutDocument>
     */
    private function statementsFor(Collection $payouts): Collection
    {
        if ($payouts->isEmpty()) {
            return collect();
        }

        return PayoutDocument::query()
            ->whereIn('payout_id', $payouts->pluck('id')->all())
            ->whereNull('cancelled_at')
            ->orderBy('id')
            ->get(['id', 'payout_id', 'gross_amount', 'tax_amount', 'net_amount'])
            ->keyBy('payout_id');
    }

    /**
     * One money value at the column's scale, never through a float.
     *
     * The numeric check is not decoration: bcmath takes `numeric-string`, and a decimal column that
     * somehow held a non-number must fail here with a message that names the value rather than
     * inside bcadd() with none.
     *
     * @return numeric-string
     */
    private function exact(mixed $value): string
    {
        $decimal = trim(is_string($value) ? $value : (string) $value);

        if (! is_numeric($decimal)) {
            throw new \InvalidArgumentException(sprintf('Expected a decimal amount, got "%s".', $decimal));
        }

        return bcadd($decimal, '0', self::SCALE);
    }

    /** RFC 4180 quoting, so a reference containing a comma cannot shift a column. */
    private function csvField(string $value): string
    {
        if ($value === '' || ! str_contains($value, ',') && ! str_contains($value, '"') && ! str_contains($value, "\n")) {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }
}
