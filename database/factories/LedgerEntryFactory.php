<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\LedgerEntryType;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LedgerEntry>
 *
 * A single entry is HALF of a posting. Double entry is enforced by the finance
 * services, not here: a test that needs a balanced pair must create both sides.
 */
class LedgerEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ledger_account_id' => LedgerAccount::factory(),
            'financial_transaction_id' => FinancialTransaction::factory(),
            'wallet_id' => null,
            'type' => LedgerEntryType::Debit,
            'amount' => '1000.00',
            'currency' => Currency::THB,
            'balance_after' => null,
            'description' => 'Factory-made ledger entry',
            'posted_at' => now(),
        ];
    }

    public function debit(string $amount = '1000.00'): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LedgerEntryType::Debit,
            'amount' => $amount,
        ]);
    }

    public function credit(string $amount = '1000.00'): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LedgerEntryType::Credit,
            'amount' => $amount,
        ]);
    }
}
