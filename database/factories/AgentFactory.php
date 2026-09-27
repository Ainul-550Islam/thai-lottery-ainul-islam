<?php

namespace Database\Factories;

use App\Enums\AgentStatus;
use App\Enums\Currency;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Agent>
 */
class AgentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agent_code' => 'AG-'.Str::upper(Str::random(8)),
            'user_id' => User::factory(),
            'parent_agent_id' => null,
            'status' => AgentStatus::Inactive,
            'currency' => Currency::THB,
            'commission_rate' => '0.0500',
            'total_referrals' => 0,
            'total_commission_earned' => '0.00',
            'total_commission_paid' => '0.00',
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentStatus::Active,
            'approved_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => 'Compliance review',
        ]);
    }
}
