<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BingoLotteryDraw;
use App\Models\BingoLotteryResult;
use App\Models\BingoLotteryResultVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BingoLotteryResult>
 *
 * LEADING ZEROS SURVIVE THE FACTORY TOO. Every value is produced with
 * str_pad() on a STRING, never with a numeric formatter, so a factory row is a
 * valid test of the width guarantee rather than an accidental exception to it.
 * Faker's numerify() is deliberately not used: it can return a value whose
 * leading character is a zero that a later int cast would eat, and the point
 * is to make that impossible everywhere.
 */
class BingoLotteryResultFactory extends Factory
{
    protected $model = BingoLotteryResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'draw_id' => BingoLotteryDraw::factory(),
            'result_version_id' => BingoLotteryResultVersion::factory(),
            'first_6_mega' => $this->digits(6),
            'three_mega' => $this->digits(3),
            'two_mega' => $this->digits(2),
            'result_status' => BingoLotteryDraw::RESULT_PUBLISHED,
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
            'first_6_mega' => null,
            'three_mega' => null,
            'two_mega' => null,
            'result_status' => BingoLotteryDraw::RESULT_UNAVAILABLE,
        ]);
    }

    /** Force exact values (used to prove leading-zero round-trips). */
    public function withNumbers(string $firstSix, string $threeBall, string $twoBall): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_6_mega' => $firstSix,
            'three_mega' => $threeBall,
            'two_mega' => $twoBall,
            'result_status' => BingoLotteryDraw::RESULT_PUBLISHED,
        ]);
    }

    private function digits(int $width): string
    {
        return str_pad((string) $this->faker->numberBetween(0, (10 ** $width) - 1), $width, '0', STR_PAD_LEFT);
    }
}
