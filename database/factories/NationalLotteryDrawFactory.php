<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DrawPublicationStatus;
use App\Enums\GloSourceState;
use App\Models\NationalLotteryDraw;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NationalLotteryDraw>
 *
 * A draw carries NO numbers, so this factory invents none. The date is
 * relative to now() rather than a hard-coded calendar date, so the fixture
 * cannot silently become "a draw in the past" as the year rolls over.
 */
class NationalLotteryDrawFactory extends Factory
{
    protected $model = NationalLotteryDraw::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = now(config('national_lottery.timezone', 'Asia/Bangkok'))->subDays(
            $this->faker->numberBetween(1, 900),
        );

        $iso = $date->toDateString();

        return [
            'uuid' => (string) Str::uuid(),
            'draw_reference' => NationalLotteryDraw::buildReference($iso).'-'.Str::upper(Str::random(4)),
            'draw_date' => $iso,
            'draw_year' => (int) $date->format('Y'),
            'status' => NationalLotteryDraw::STATUS_AWAITING_RESULT,
            'publication_status' => DrawPublicationStatus::Pending,
            'published_at' => null,
            'source_state' => GloSourceState::Unavailable->value,
            'current_result_version_id' => null,
            'metadata' => [],
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => NationalLotteryDraw::STATUS_RESULT_RECORDED,
            'publication_status' => DrawPublicationStatus::Published,
            'published_at' => now(),
            'source_state' => GloSourceState::InternalReconciled->value,
        ]);
    }

    public function onDate(string $isoDate): static
    {
        return $this->state(fn (array $attributes): array => [
            'draw_date' => $isoDate,
            'draw_year' => (int) substr($isoDate, 0, 4),
            'draw_reference' => NationalLotteryDraw::buildReference($isoDate),
        ]);
    }
}
