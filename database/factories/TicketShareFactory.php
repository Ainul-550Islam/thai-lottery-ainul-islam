<?php

namespace Database\Factories;

use App\Enums\TicketShareStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TicketShare>
 *
 * Only the HASH of the share token is stored; the token itself never lands in a row.
 */
class TicketShareFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'ticket_id' => Ticket::factory(),
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', Str::random(48)),
            'status' => TicketShareStatus::Active,
            'views' => 0,
            'expires_at' => now()->addHours(24),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketShareStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketShareStatus::Expired,
            'expires_at' => now()->subHour(),
        ]);
    }
}
