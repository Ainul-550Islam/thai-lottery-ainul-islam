<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\TicketStatus;
use App\Models\Draw;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'ticket_number' => 'TKT-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'user_id' => User::factory(),
            'draw_id' => Draw::factory(),
            'status' => TicketStatus::Pending,
            'currency' => Currency::THB,
            'total_amount' => '0.00',
            'total_bets' => 0,
            'total_numbers' => 0,
            'issued_at' => now(),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::Expired,
            'expired_at' => now(),
        ]);
    }
}
