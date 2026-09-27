<?php

namespace Database\Factories;

use App\Models\Bet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BetItem>
 */
class BetItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bet_id' => Bet::factory(),
            'number' => '12',
            'position' => null,
            'amount' => '100.00',
            'payout_multiplier' => 70,
            'potential_payout' => '7000.00',
            'is_winner' => false,
            'actual_payout' => '0.00',
        ];
    }

    public function winner(string $payout = '7000.00'): static
    {
        return $this->state(fn (array $attributes) => [
            'is_winner' => true,
            'actual_payout' => $payout,
        ]);
    }
}
