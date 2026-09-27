<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PcsoLotteryDraw;
use App\Models\PcsoLotteryResult;
use App\Models\PcsoLotteryResultVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PcsoLotteryResult>
 *
 * LEADING ZEROS SURVIVE THE FACTORY TOO. Every value is produced with
 * str_pad() on a STRING, never with a numeric formatter, so a factory row is a
 * valid test of the width guarantee rather than an accidental exception to it.
 * Faker's numerify() is deliberately not used: it can return a value whose
 * leading character is a zero that a later int cast would eat, and the point
 * is to make that impossible everywhere.
 */
class PcsoLotteryResultFactory extends Factory
{
    protected $model = PcsoLotteryResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'draw_id' => PcsoLotteryDraw::factory(),
            'result_version_id' => PcsoLotteryResultVersion::factory(),
            'six_digit' => $this->digits(6),
            'three_digit' => $this->digits(3),
            'two_digit' => $this->digits(2),
            'result_status' => PcsoLotteryDraw::RESULT_PUBLISHED,
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
            'six_digit' => null,
            'three_digit' => null,
            'two_digit' => null,
            'result_status' => PcsoLotteryDraw::RESULT_UNAVAILABLE,
        ]);
    }

    /** Force exact values (used to prove leading-zero round-trips). */
    public function withNumbers(string $firstSix, string $threeBall, string $twoBall): static
    {
        return $this->state(fn (array $attributes): array => [
            'six_digit' => $firstSix,
            'three_digit' => $threeBall,
            'two_digit' => $twoBall,
            'result_status' => PcsoLotteryDraw::RESULT_PUBLISHED,
        ]);
    }

    private function digits(int $width): string
    {
        return str_pad((string) $this->faker->numberBetween(0, (10 ** $width) - 1), $width, '0', STR_PAD_LEFT);
    }
}
