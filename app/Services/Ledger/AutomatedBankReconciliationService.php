<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Enums\Currency;
use App\Models\DepositTransaction;
use App\Models\LedgerEntry;
use App\Models\Payout;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Legacy internal aggregate comparison; it is NOT a bank reconciliation.
 * There is no independently authenticated bank-statement feed in this service.
 * Matching totals do not prove that individual transactions match, nor that a
 * bank ever received or paid the money. Reconciliation must remain unverified.
 */
class AutomatedBankReconciliationService
{
    /**
     * Compare the legacy deposit/payout records and their hash-chain journal
     * for one explicitly identified currency. Canonical wallet postings and
     * other ledgers are outside this narrow diagnostic's scope.
     *
     * @return array{
     *     date:string, currency:string, scope:string,
     *     recorded_deposits_total:string, ledger_deposits_total:string, deposits_variance:string,
     *     recorded_payouts_total:string, ledger_payouts_total:string, payouts_variance:string,
     *     internal_aggregate_balanced:bool, external_evidence_verified:bool, is_balanced:bool
     * }
     */
    public function reconcileDate(Carbon $date, ?string $currency = null): array
    {
        $code = Currency::tryFromCode($currency ?? (string) config('payment.currency.default', 'THB'));
        if ($code === null) {
            throw new InvalidArgumentException('A supported reconciliation currency is required.');
        }
        $code = $code->value;
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        $deposits = $this->sumExact(DepositTransaction::query()
            ->where('currency', $code)->where('status', 'COMPLETED')
            ->whereBetween('completed_at', [$start, $end]), 'net_amount');
        $ledgerDeposits = $this->sumExact(LedgerEntry::query()
            ->where('currency', $code)->where('direction', 'DEBIT')
            ->whereHas('transaction', function ($query) use ($code, $start, $end): void {
                $query->where('currency', $code)->where('status', 'POSTED')
                    ->where('reference_type', 'DEPOSIT_SETTLEMENT')
                    ->whereBetween('posted_at', [$start, $end]);
            }), 'amount');

        $payouts = $this->sumExact(Payout::query()
            ->where('currency', $code)->where('payout_status', 'COMPLETED')
            ->whereBetween('paid_at', [$start, $end]), 'net_amount');
        $ledgerPayouts = $this->sumExact(LedgerEntry::query()
            ->where('currency', $code)->where('direction', 'CREDIT')
            ->whereHas('transaction', function ($query) use ($code, $start, $end): void {
                $query->where('currency', $code)->where('status', 'POSTED')
                    ->where('reference_type', 'PRIZE_PAYOUT')
                    ->whereBetween('posted_at', [$start, $end]);
            }), 'amount');

        $depositVariance = bcsub($deposits, $ledgerDeposits, 4);
        $payoutVariance = bcsub($payouts, $ledgerPayouts, 4);
        $internalBalanced = bccomp($depositVariance, '0', 4) === 0
            && bccomp($payoutVariance, '0', 4) === 0;
        $report = [
            'date' => $date->toDateString(),
            'currency' => $code,
            'scope' => 'legacy_internal_aggregates_only',
            'recorded_deposits_total' => $deposits,
            'ledger_deposits_total' => $ledgerDeposits,
            'deposits_variance' => $depositVariance,
            'recorded_payouts_total' => $payouts,
            'ledger_payouts_total' => $ledgerPayouts,
            'payouts_variance' => $payoutVariance,
            'internal_aggregate_balanced' => $internalBalanced,
            'external_evidence_verified' => false,
            // An internal numerical match cannot certify a bank reconciliation.
            'is_balanced' => false,
        ];

        if (! $internalBalanced) {
            Log::critical('Legacy internal ledger aggregate mismatch; bank evidence is not verified.', $report);
        } else {
            Log::notice('Legacy internal ledger aggregates agree; bank evidence remains unverified.', $report);
        }

        return $report;
    }

    /** Add decimal strings, never SQL/driver floating-point aggregate results. */
    private function sumExact(Builder $query, string $column): string
    {
        if (! in_array($column, ['net_amount', 'amount'], true)) {
            throw new InvalidArgumentException('Unexpected reconciliation amount column.');
        }
        $total = '0.0000';
        foreach ($query->selectRaw('CAST('.$column.' AS CHAR) AS exact_amount')->cursor() as $row) {
            $value = $row->getAttribute('exact_amount');
            if (! is_string($value) || preg_match('/^\d+(?:\.\d{1,4})?$/D', $value) !== 1) {
                throw new RuntimeException('Invalid or missing amount in reconciliation input.');
            }
            $total = bcadd($total, $value, 4);
        }

        return $total;
    }
}
