<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\DepositTransaction;
use App\Models\LedgerEntry;
use App\Models\Payout;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AutomatedBankReconciliationService
{
    /**
     * Reconcile daily financial activity between bank deposits, payouts, and double-entry ledger.
     *
     * @return array{
     *     date: string,
     *     bank_deposits_total: string,
     *     ledger_deposits_total: string,
     *     deposits_variance: string,
     *     bank_payouts_total: string,
     *     ledger_payouts_total: string,
     *     payouts_variance: string,
     *     is_balanced: bool
     * }
     */
    public function reconcileDate(Carbon $date): array
    {
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        $bankDepositsTotal = bcadd((string) (DepositTransaction::where('status', 'COMPLETED')
            ->whereBetween('completed_at', [$start, $end])
            ->sum('net_amount') ?? '0'), '0', 4);

        $ledgerDepositsTotal = bcadd((string) (LedgerEntry::whereHas('transaction', function ($q) use ($start, $end) {
            $q->where('reference_type', 'DEPOSIT_SETTLEMENT')
                ->whereBetween('posted_at', [$start, $end]);
        })->where('direction', 'DEBIT')->sum('amount') ?? '0'), '0', 4);

        $depositsVariance = bcsub($bankDepositsTotal, $ledgerDepositsTotal, 4);

        $bankPayoutsTotal = bcadd((string) (Payout::where('payout_status', 'COMPLETED')
            ->whereBetween('paid_at', [$start, $end])
            ->sum('net_amount') ?? '0'), '0', 4);

        $ledgerPayoutsTotal = bcadd((string) (LedgerEntry::whereHas('transaction', function ($q) use ($start, $end) {
            $q->where('reference_type', 'PRIZE_PAYOUT')
                ->whereBetween('posted_at', [$start, $end]);
        })->where('direction', 'CREDIT')->sum('amount') ?? '0'), '0', 4);

        $payoutsVariance = bcsub($bankPayoutsTotal, $ledgerPayoutsTotal, 4);

        $isBalanced = bccomp($depositsVariance, '0.0000', 4) === 0 && bccomp($payoutsVariance, '0.0000', 4) === 0;

        if (! $isBalanced) {
            Log::critical("Financial reconciliation mismatch detected for date [{$date->toDateString()}]: Deposit Variance: {$depositsVariance}, Payout Variance: {$payoutsVariance}");
        } else {
            Log::info("Daily financial bank-to-ledger reconciliation PASSED with ZERO variance for date [{$date->toDateString()}].");
        }

        return [
            'date' => $date->toDateString(),
            'bank_deposits_total' => $bankDepositsTotal,
            'ledger_deposits_total' => $ledgerDepositsTotal,
            'deposits_variance' => $depositsVariance,
            'bank_payouts_total' => $bankPayoutsTotal,
            'ledger_payouts_total' => $ledgerPayoutsTotal,
            'payouts_variance' => $payoutsVariance,
            'is_balanced' => $isBalanced,
        ];
    }
}
