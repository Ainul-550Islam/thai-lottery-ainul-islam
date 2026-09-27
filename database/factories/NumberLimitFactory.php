<?php

namespace Database\Factories;

use App\Enums\BetType;
use App\Enums\LimitStatus;
use App\Models\Draw;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NumberLimit>
 *
 * (draw_id, bet_type, number) is unique — callers that need several limits on one
 * draw must vary the number themselves.
 */
class NumberLimitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'draw_id' => Draw::factory(),
            'bet_type' => BetType::TwoD,
            'number' => str_pad((string) fake()->unique()->numberBetween(0, 99), 2, '0', STR_PAD_LEFT),
            'max_amount' => '100000.00',
            'current_amount' => '0.00',
            'maximum_payout_exposure' => null,
            'current_payout_exposure' => '0.00',
            'status' => LimitStatus::Active,
        ];
    }

    public function forNumber(string $number, BetType $type = BetType::TwoD): static
    {
        return $this->state(fn (array $attributes) => [
            'number' => $number,
            'bet_type' => $type,
        ]);
    }

    public function exceeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LimitStatus::Exceeded,
            'current_amount' => $attributes['max_amount'] ?? '100000.00',
            'exceeded_at' => now(),
        ]);
    }
}
