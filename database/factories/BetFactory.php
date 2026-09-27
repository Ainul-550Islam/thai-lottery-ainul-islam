<?php

namespace Database\Factories;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Models\Draw;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Bet>
 */
class BetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'bet_number' => 'BET-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'user_id' => User::factory(),
            'draw_id' => Draw::factory(),
            'ticket_id' => null,
            'payout_id' => null,
            'type' => BetType::TwoD,
            'status' => BetStatus::Pending,
            'currency' => Currency::THB,
            'stake_amount' => '100.00',
            'potential_payout' => '0.00',
            'actual_payout' => '0.00',
            'total_numbers' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'placed_at' => now(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BetStatus::Active,
        ]);
    }

    public function won(string $payout = '7000.00'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BetStatus::Won,
            'actual_payout' => $payout,
            'won_at' => now(),
        ]);
    }

    public function lost(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BetStatus::Lost,
            'actual_payout' => '0.00',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BetStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_reason' => 'Cancelled inside the amendment window',
        ]);
    }
}
