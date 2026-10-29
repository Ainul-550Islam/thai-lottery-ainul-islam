<?php

declare(strict_types=1);

namespace App\Console\Commands\Ledger;

use App\Services\Ledger\AutomatedBankReconciliationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunDailyBankReconciliationCommand extends Command
{
    protected $signature = 'ledger:reconcile-bank
        {date? : Date to inspect in YYYY-MM-DD format (defaults to yesterday)}
        {--currency= : One supported ISO currency; defaults to the configured merchant currency}';

    protected $description = 'Compare legacy internal aggregates; external bank evidence is not available.';

    public function handle(AutomatedBankReconciliationService $reconciliationService): int
    {
        $dateStr = $this->argument('date');
        $date = $dateStr ? Carbon::parse($dateStr) : Carbon::yesterday();
        $currency = $this->option('currency');
        $result = $reconciliationService->reconcileDate($date, is_string($currency) ? $currency : null);
        $unit = $result['currency'];

        $this->warn('Internal legacy aggregate comparison only; individual transactions are not matched.');
        $this->table(['Internal metric', 'Amount'], [
            ['Date', $result['date']],
            ['Recorded deposits', $result['recorded_deposits_total'].' '.$unit],
            ['Journal deposits', $result['ledger_deposits_total'].' '.$unit],
            ['Deposit variance', $result['deposits_variance'].' '.$unit],
            ['Recorded prize payouts', $result['recorded_payouts_total'].' '.$unit],
            ['Journal prize payouts', $result['ledger_payouts_total'].' '.$unit],
            ['Payout variance', $result['payouts_variance'].' '.$unit],
            ['Internal aggregates', $result['internal_aggregate_balanced'] ? 'NUMERICALLY EQUAL' : 'MISMATCH'],
        ]);
        $this->error('External bank evidence is not verified; bank reconciliation remains unverified.');

        // No bank statement or authenticated provider evidence is supplied to
        // this command. Even zero variance cannot produce a verified exit code.
        return self::FAILURE;
    }
}
