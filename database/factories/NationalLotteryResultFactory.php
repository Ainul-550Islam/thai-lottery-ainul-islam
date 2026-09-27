<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NationalLotteryDraw;
use App\Models\NationalLotteryResult;
use App\Models\NationalLotteryResultVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NationalLotteryResult>
 *
 * LEADING ZEROS SURVIVE THE FACTORY TOO. Every value is produced with
 * str_pad() on a STRING, never with a numeric formatter, so a factory row is
 * a valid test of the width guarantee rather than an accidental exception to
 * it. numerify() is deliberately not used: it can return a value whose leading
 * character is a zero that a later int cast would eat, and the point is to
 * make that impossible everywhere.
 */
class NationalLotteryResultFactory extends Factory
{
    protected $model = NationalLotteryResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $first = $this->digits(6);

        return [
            'draw_id' => NationalLotteryDraw::factory(),
            'result_version_id' => NationalLotteryResultVersion::factory(),
            'first_prize' => $first,
            'three_up' => $this->digits(3),
            'two_up' => $this->digits(2),
            'two_down' => $this->digits(2),
            'three_front' => [$this->digits(3), $this->digits(3)],
            'three_after' => [$this->digits(3), $this->digits(3)],
            'three_front_count' => 2,
            'three_after_count' => 2,
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
     * Force an exact first prize (used to prove leading-zero round-trips).
     */
    public function withFirstPrize(string $number): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_prize' => $number,
        ]);
    }

    private function digits(int $width): string
    {
        return str_pad((string) $this->faker->numberBetween(0, (10 ** $width) - 1), $width, '0', STR_PAD_LEFT);
    }
}
