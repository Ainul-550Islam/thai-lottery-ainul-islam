<?php

namespace Database\Factories;

use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AgentCommission>
 */
class AgentCommissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference_number' => 'COM-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'agent_id' => Agent::factory(),
            'user_id' => null,
            'bet_id' => null,
            'draw_id' => null,
            'financial_transaction_id' => null,
            'status' => CommissionStatus::Accrued,
            'currency' => Currency::THB,
            'base_amount' => '100.00',
            'commission_rate' => '0.0500',
            'commission_amount' => '5.00',
            'accrued_at' => now(),
        ];
    }

    public function payable(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CommissionStatus::Payable,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CommissionStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function reversed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CommissionStatus::Reversed,
            'reversed_at' => now(),
        ]);
    }
}
