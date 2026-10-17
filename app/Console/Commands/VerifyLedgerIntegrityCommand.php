<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ledger\LedgerReconciliationService;
use Illuminate\Console\Command;

class VerifyLedgerIntegrityCommand extends Command
{
    protected $signature = 'ledger:audit-integrity';
    protected $description = 'Runs a full mathematical and cryptographic audit across the double-entry general ledger.';

    public function handle(LedgerReconciliationService $reconciliationService): int
    {
        $this->info('Starting System Financial Reconciliation & Hash Chain Verification...');

        $report = $reconciliationService->auditSystemIntegrity();

        $this->table(['Metric', 'Value'], [
            ['Audit Status', $report['status']],
            ['Total Debits', $report['total_debits'] . ' THB'],
            ['Total Credits', $report['total_credits'] . ' THB'],
            ['Ledger Delta', $report['ledger_balance_delta'] . ' THB'],
            ['Reconciled User Accounts', (string) $report['reconciled_accounts_count']],
            ['Wallet Discrepancies Count', (string) count($report['wallet_discrepancies'])],
            ['Tampered Transactions Count', (string) count($report['tampered_transactions'])],
        ]);

        if ($report['status'] !== 'HEALTHY') {
            $this->error('🚨 CRITICAL FINANCIAL INTEGRITY FAILURE DETECTED!');

            if (!empty($report['wallet_discrepancies'])) {
                $this->warn('Wallet Discrepancies Found:');
                $this->table(['User ID', 'Wallet Balance', 'Ledger Derived', 'Difference'], $report['wallet_discrepancies']);
            }

            if (!empty($report['tampered_transactions'])) {
                $this->error('Tampered Ledger Transactions Detected:');
                $this->table(['TX ID', 'TX Number', 'Issue', 'Expected', 'Actual'], $report['tampered_transactions']);
            }

            return self::FAILURE;
        }

        $this->info('✅ All financial accounts balanced perfectly. Cryptographic chain verified intact.');

        return self::SUCCESS;
    }
}
