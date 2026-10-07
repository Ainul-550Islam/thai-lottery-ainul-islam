<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\LedgerEntryType;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\Wallet;
use App\Models\WalletLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WalletLedger> */
final class WalletLedgerFactory extends Factory
{
    protected $model = WalletLedger::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ledger_account_id' => LedgerAccount::factory(),
            'financial_transaction_id' => FinancialTransaction::factory(),
            'wallet_id' => Wallet::factory(),
            'type' => LedgerEntryType::Debit,
            'amount' => '1000.00',
            'currency' => Currency::THB,
            'balance_after' => '0.00',
            'description' => 'Factory-made wallet ledger entry',
            'posted_at' => now(),
        ];
    }
}
