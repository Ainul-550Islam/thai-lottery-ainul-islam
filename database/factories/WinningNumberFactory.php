<?php

namespace Database\Factories;

use App\Enums\BetType;
use App\Models\Draw;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WinningNumber>
 *
 * (draw_id, bet_type, number, prize_tier) is unique.
 */
class WinningNumberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'draw_id' => Draw::factory(),
            'bet_type' => BetType::TwoD,
            'number' => '23',
            'prize_tier' => null,
            'position' => null,
            'payout_multiplier' => 70,
            'total_winners' => 0,
            'total_payout' => '0.00',
        ];
    }

    public function forNumber(string $number, BetType $type = BetType::TwoD): static
    {
        return $this->state(fn (array $attributes) => [
            'number' => $number,
            'bet_type' => $type,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'published_at' => now(),
        ]);
    }
}
