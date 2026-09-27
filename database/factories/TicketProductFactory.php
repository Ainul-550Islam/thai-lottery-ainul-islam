<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\TicketProductStatus;
use App\Models\Draw;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TicketProduct>
 */
class TicketProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_key' => 'PRD-'.Str::upper(Str::random(12)),
            'product_code' => 'L6-'.Str::upper(Str::random(6)),
            'draw_id' => Draw::factory(),
            'denomination' => '80.00',
            'currency' => Currency::THB,
            'units_total' => 1000,
            'units_allocated' => 0,
            'status' => TicketProductStatus::Draft,
            'starts_at' => now(),
            'ends_at' => now()->addDays(14),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketProductStatus::Active,
            'activated_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketProductStatus::Closed,
            'closed_at' => now(),
        ]);
    }
}
