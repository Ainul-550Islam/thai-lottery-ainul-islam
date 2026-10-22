<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LedgerAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SystemChartOfAccountsSeeder extends Seeder
{
    /**
     * Seed GAAP compliant General Ledger Chart of Accounts.
     */
    public function run(): void
    {
        $accounts = [
            // Assets (1000 - 1999)
            [
                'account_number' => '1010-CASH-THB',
                'name' => 'Main Operating Cash / Vault (THB)',
                'type' => 'ASSET',
                'currency' => 'THB',
            ],
            [
                'account_number' => '1020-SYS-ASSET-GATEWAY-PROMPTPAY-THB',
                'name' => 'PromptPay Gateway Clearing Asset',
                'type' => 'ASSET',
                'currency' => 'THB',
            ],
            [
                'account_number' => '1030-SYS-ASSET-GATEWAY-USDT-THB',
                'name' => 'USDT Crypto Gateway Clearing Asset',
                'type' => 'ASSET',
                'currency' => 'THB',
            ],
            [
                'account_number' => '1040-SYS-ASSET-BANK-BANK_TRANSFER-THB',
                'name' => 'Commercial Bank Disbursement Asset Account',
                'type' => 'ASSET',
                'currency' => 'THB',
            ],

            // Liabilities (2000 - 2999)
            [
                'account_number' => '2010-USER-TOTAL-LIABILITY-THB',
                'name' => 'Consolidated User Wallet Liabilities (THB)',
                'type' => 'LIABILITY',
                'currency' => 'THB',
            ],
            [
                'account_number' => '2020-AGENT-COMMISSION-PAYABLE-THB',
                'name' => 'Agent Commission Payable Account',
                'type' => 'LIABILITY',
                'currency' => 'THB',
            ],

            // Revenues (4000 - 4999)
            [
                'account_number' => '4010-SYS-REV-THB',
                'name' => 'Lottery Gross Ticket Sales Revenue',
                'type' => 'REVENUE',
                'currency' => 'THB',
            ],

            // Expenses (5000 - 5999)
            [
                'account_number' => '5010-SYS-EXP-PAYOUT-THB',
                'name' => 'Lottery Prize Payout Expense',
                'type' => 'EXPENSE',
                'currency' => 'THB',
            ],
            [
                'account_number' => '5020-COMMISSION-EXPENSE-THB',
                'name' => 'Agent Network Distribution Expense',
                'type' => 'EXPENSE',
                'currency' => 'THB',
            ],
        ];

        foreach ($accounts as $account) {
            LedgerAccount::firstOrCreate(
                ['account_number' => $account['account_number']],
                [
                    'id' => Str::uuid()->toString(),
                    'name' => $account['name'],
                    'type' => $account['type'],
                    'currency' => $account['currency'],
                    'current_balance' => '0.0000',
                    'user_id' => null,
                    'is_active' => true,
                ]
            );
        }
    }
}
