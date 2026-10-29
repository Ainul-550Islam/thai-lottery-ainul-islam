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
        // A draw that is OPEN FOR BETTING has to carry its betting window.
        // DrawRiskAdmission — the authority every write ingress consults before
        // it accepts new risk — fails closed on a missing betting_open_at or
        // betting_close_at, on the reasoning that a draw whose window was never
        // written is a draw nobody can prove is currently taking bets. An open()
        // state that set only the status therefore produced a draw that looked
        // open to assertions about status while being un-sellable in practice,
        // and every purchase test built on it failed admission. The window here
        // is the honest shape of the state: opened an hour ago, closing in an hour.
        return $this->state(fn (array $attributes) => [
            'status' => DrawStatus::Open,
            'opened_at' => now(),
            'betting_open_at' => now()->subHour(),
            'betting_close_at' => now()->addHour(),
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
