<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FinancialTransaction>
 */
class FinancialTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'reference_number' => 'FT-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'user_id' => User::factory(),
            'wallet_id' => Wallet::factory(),
            'type' => TransactionType::Deposit,
            'status' => TransactionStatus::Pending,
            'currency' => Currency::THB,
            'amount' => '1000.00',
            'fee' => '0.00',
            'description' => 'Factory-made transaction',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionStatus::Completed,
            'processed_at' => now(),
        ]);
    }

    public function reversed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionStatus::Reversed,
            'reversed_at' => now(),
        ]);
    }

    public function ofType(TransactionType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
        ]);
    }
}
