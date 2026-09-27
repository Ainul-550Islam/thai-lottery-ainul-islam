<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ResponsibleGamingLimit>
 *
 * user_id is unique: one live limit row per player.
 */
class ResponsibleGamingLimitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'daily_deposit_limit' => null,
            'single_bet_limit' => null,
            'daily_wagering_limit' => null,
            'self_excluded_until' => null,
            'cool_off_until' => null,
            'self_exclusion_reason' => null,
        ];
    }

    public function withLimits(
        string $dailyDeposit = '10000.00',
        string $singleBet = '1000.00',
        string $dailyWagering = '20000.00',
    ): static {
        return $this->state(fn (array $attributes) => [
            'daily_deposit_limit' => $dailyDeposit,
            'single_bet_limit' => $singleBet,
            'daily_wagering_limit' => $dailyWagering,
        ]);
    }

    public function selfExcluded(): static
    {
        return $this->state(fn (array $attributes) => [
            'self_excluded_until' => now()->addMonths(6),
            'self_exclusion_reason' => 'Player requested exclusion',
        ]);
    }

    public function coolingOff(): static
    {
        return $this->state(fn (array $attributes) => [
            'cool_off_until' => now()->addDay(),
        ]);
    }
}
