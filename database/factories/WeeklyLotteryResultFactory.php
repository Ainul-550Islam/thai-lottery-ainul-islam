<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WeeklyLotteryDraw;
use App\Models\WeeklyLotteryResult;
use App\Models\WeeklyLotteryResultVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeeklyLotteryResult>
 *
 * LEADING ZEROS SURVIVE THE FACTORY TOO. Every value is produced with
 * str_pad() on a STRING, never with a numeric formatter, so a factory row is a
 * valid test of the width guarantee rather than an accidental exception to it.
 * Faker's numerify() is deliberately not used: it can return a value whose
 * leading character is a zero that a later int cast would eat, and the point
 * is to make that impossible everywhere.
 */
class WeeklyLotteryResultFactory extends Factory
{
    protected $model = WeeklyLotteryResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'draw_id' => WeeklyLotteryDraw::factory(),
            'result_version_id' => WeeklyLotteryResultVersion::factory(),
            'first_6' => $this->digits(6),
            'three_ball' => $this->digits(3),
            'two_ball' => $this->digits(2),
            'result_status' => WeeklyLotteryDraw::RESULT_PUBLISHED,
            'is_current' => false,
        ];
    }

    public function current(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_current' => true,
        ]);
    }

    /**
     * A draw that published nothing.
     *
     * NULLs, never zeros: '000000' would be a fabricated result wearing the
     * shape of a real one.
     */
    public function unavailable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_6' => null,
            'three_ball' => null,
            'two_ball' => null,
            'result_status' => WeeklyLotteryDraw::RESULT_UNAVAILABLE,
        ]);
    }

    /** Force exact values (used to prove leading-zero round-trips). */
    public function withNumbers(string $firstSix, string $threeBall, string $twoBall): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_6' => $firstSix,
            'three_ball' => $threeBall,
            'two_ball' => $twoBall,
            'result_status' => WeeklyLotteryDraw::RESULT_PUBLISHED,
        ]);
    }

    private function digits(int $width): string
    {
        return str_pad((string) $this->faker->numberBetween(0, (10 ** $width) - 1), $width, '0', STR_PAD_LEFT);
    }
}
