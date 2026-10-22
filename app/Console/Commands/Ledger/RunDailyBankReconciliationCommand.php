<?php

declare(strict_types=1);

namespace App\Console\Commands\Ledger;

use App\Services\Ledger\AutomatedBankReconciliationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunDailyBankReconciliationCommand extends Command
{
    protected $signature = 'ledger:reconcile-bank {date? : Date to reconcile in YYYY-MM-DD format (defaults to yesterday)}';

    protected $description = 'Executes automated 3-way financial reconciliation between bank clearing, payouts, and double-entry ledger.';

    public function handle(AutomatedBankReconciliationService $reconciliationService): int
    {
        $dateStr = $this->argument('date');
        $date = $dateStr ? Carbon::parse($dateStr) : Carbon::yesterday();

        $this->info("Running Automated 3-Way Financial Reconciliation for [{$date->toDateString()}]...");

        $res = $reconciliationService->reconcileDate($date);

        $this->table(['Reconciliation Metric', 'Amount (THB)'], [
            ['Reconciliation Date', $res['date']],
            ['Bank Confirmed Deposits Total', $res['bank_deposits_total'].' THB'],
            ['Double-Entry Ledger Deposits Total', $res['ledger_deposits_total'].' THB'],
            ['Deposit Variance (Bank vs Ledger)', $res['deposits_variance'].' THB'],
            ['Bank Confirmed Payouts Total', $res['bank_payouts_total'].' THB'],
            ['Double-Entry Ledger Payouts Total', $res['ledger_payouts_total'].' THB'],
            ['Payout Variance (Bank vs Ledger)', $res['payouts_variance'].' THB'],
            ['Reconciliation Balance Status', $res['is_balanced'] ? 'PERFECTLY BALANCED (0 VARIANCE)' : 'MISMATCH DETECTED'],
        ]);

        if ($res['is_balanced']) {
            $this->info('✅ Financial reconciliation verified: ZERO discrepancy between bank clearing and double-entry ledger.');

            return self::SUCCESS;
        }

        $this->error('❌ CRITICAL: Financial discrepancy detected during bank-to-ledger reconciliation.');

        return self::FAILURE;
    }
}
