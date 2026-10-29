<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ledger\LedgerReconciliationService;
use Illuminate\Console\Command;

class VerifyLedgerIntegrityCommand extends Command
{
    protected $signature = 'ledger:audit-integrity';

    protected $description = 'Run scoped internal ledger arithmetic, wallet and hash-chain checks.';

    public function handle(LedgerReconciliationService $reconciliationService): int
    {
        $this->info('Starting scoped internal ledger and hash-chain checks...');
        $report = $reconciliationService->auditSystemIntegrity();
        $this->table(['Metric', 'Value'], [
            ['Internal check status', $report['status']],
            ['Reconciled user accounts', (string) $report['reconciled_accounts_count']],
            ['User accounts without a wallet', (string) count($report['unmatched_user_accounts'])],
            ['Wallet discrepancies', (string) count($report['wallet_discrepancies'])],
            ['Currency or entry identity issues', (string) count($report['currency_identity_issues'])],
            ['Unbalanced transaction/currency pairs', (string) count($report['transaction_balance_issues'])],
            ['Hash-chain issues', (string) count($report['tampered_transactions'])],
        ]);
        $currencyRows = [];
        foreach ($report['by_currency'] as $currency => $totals) {
            $currencyRows[] = [$currency, $totals['debits'], $totals['credits'], $totals['delta']];
        }
        if ($currencyRows !== []) {
            $this->table(['Currency', 'Debits', 'Credits', 'Delta'], $currencyRows);
        }

        if ($report['status'] !== 'HEALTHY') {
            $this->error('Internal financial integrity discrepancy detected.');
            if ($report['wallet_discrepancies'] !== []) {
                $this->table(['User ID', 'Currency', 'Wallet balance', 'Ledger derived', 'Difference'], $report['wallet_discrepancies']);
            }
            if ($report['unmatched_user_accounts'] !== []) {
                $this->table(['Account ID', 'User ID', 'Currency'], $report['unmatched_user_accounts']);
            }
            if ($report['currency_identity_issues'] !== []) {
                $this->table(['Entry ID', 'Issue'], $report['currency_identity_issues']);
            }
            if ($report['transaction_balance_issues'] !== []) {
                $this->table(['Financial transaction ID', 'Currency', 'Delta'], $report['transaction_balance_issues']);
            }
            if ($report['tampered_transactions'] !== []) {
                $this->warn('Hash-chain issues: '.count($report['tampered_transactions']));
            }

            return self::FAILURE;
        }

        $this->info('Scoped internal checks passed; external bank evidence and overall obligation completeness were not verified.');

        return self::SUCCESS;
    }
}
