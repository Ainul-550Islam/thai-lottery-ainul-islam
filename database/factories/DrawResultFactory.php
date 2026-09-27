<?php

namespace Database\Factories;

use App\Models\Draw;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DrawResult>
 *
 * first_prize is a STRING: a six-digit number keeps its leading zeros.
 */
class DrawResultFactory extends Factory
{
    public function definition(): array
    {
        return [
            'draw_id' => Draw::factory(),
            'first_prize' => '456123',
            'second_prize' => [],
            'third_prize' => [],
            'consolation_prizes' => [],
            'all_numbers' => [],
            'total_winners' => 0,
            'total_payout' => '0.00',
            'house_profit' => '0.00',
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'published_at' => now(),
        ]);
    }

    public function withFirstPrize(string $number): static
    {
        return $this->state(fn (array $attributes) => [
            'first_prize' => $number,
        ]);
    }
}
