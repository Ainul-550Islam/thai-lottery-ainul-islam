<?php

namespace Database\Factories;

use App\Enums\DrawStatus;
use App\Enums\DrawType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Draw>
 */
class DrawFactory extends Factory
{
    public function definition(): array
    {
        return [
            'draw_number' => 'DRW-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
            'type' => DrawType::TwoD,
            'status' => DrawStatus::Scheduled,
            'scheduled_at' => now()->addHour(),
            'total_bets' => 0,
            'total_amount_wagered' => '0.00',
            'total_payout' => '0.00',
            'house_profit' => '0.00',
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DrawStatus::Open,
            'opened_at' => now(),
            // A draw cannot be open with no betting window: the canonical
            // opener always derives betting_close_at with the draw (the
            // window ends at the draw or at the configured minutes-before
            // cut-off). A state that produced an unwindowed open draw let
            // the dashboard's countdown target render EMPTY — an open draw
            // whose betting_close_at is null is not a state the platform
            // itself can ever be in.
            'betting_close_at' => ($attributes['scheduled_at'] ?? null) instanceof \DateTimeInterface
                ? \Illuminate\Support\Carbon::instance($attributes['scheduled_at'])
                    ->subMinutes(max(0, (int) config('lottery.closing.minutes_before_draw', 5)))
                : now()->addMinutes(55),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DrawStatus::Closed,
            'opened_at' => now()->subHour(),
            'closed_at' => now(),
        ]);
    }
}
