<?php

namespace Database\Factories;

use App\Enums\BetAmendmentStatus;
use App\Enums\Currency;
use App\Models\Bet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BetAmendment>
 */
class BetAmendmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'bet_id' => Bet::factory(),
            'replacement_bet_id' => null,
            'status' => BetAmendmentStatus::Pending,
            'currency' => Currency::THB,
            'old_number' => '12',
            'old_stake' => '100.00',
            'new_number' => '34',
            'new_stake' => '100.00',
            'refunded_amount' => '0.00',
            'charged_amount' => null,
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addMinutes(15),
        ];
    }

    public function applied(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BetAmendmentStatus::Applied,
            'applied_at' => now(),
            'refunded_amount' => $attributes['old_stake'] ?? '100.00',
            'charged_amount' => $attributes['new_stake'] ?? '100.00',
        ]);
    }

    public function failed(string $reason = 'Draw closed before the amendment applied'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BetAmendmentStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BetAmendmentStatus::Expired,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
