<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Deposit>
 */
class DepositFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'reference_number' => 'DEP-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'user_id' => User::factory(),
            'wallet_id' => Wallet::factory(),
            'financial_transaction_id' => null,
            'method' => PaymentMethod::Manual,
            'provider' => null,
            'provider_reference' => null,
            'status' => DepositStatus::Pending,
            'currency' => Currency::THB,
            'amount' => '1000.00',
            'fee' => '0.00',
            'net_amount' => '1000.00',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DepositStatus::Approved,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DepositStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Gateway declined the charge'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DepositStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }
}
