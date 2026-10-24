<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DrawPublicationStatus;
use App\Enums\GloSourceState;
use App\Models\WeeklyLotteryDraw;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WeeklyLotteryDraw>
 *
 * A draw carries NO numbers, so this factory invents none. The date is
 * relative to now() rather than a hard-coded calendar date, so the fixture
 * cannot silently become "a draw in the past" as the year rolls over.
 *
 * The repository's ModelFactoryIntegrityTest requires a factory for every
 * model using HasFactory, and requires create() to persist a real row - which
 * is why this exists rather than being skipped.
 */
class WeeklyLotteryDrawFactory extends Factory
{
    protected $model = WeeklyLotteryDraw::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $timezone = config('weekly_lottery.timezone', 'Asia/Bangkok');
        $date = now($timezone)->subDays($this->faker->numberBetween(1, 900));
        $iso = $date->toDateString();

        return [
            'uuid' => (string) Str::uuid(),
            // Suffixed so two factory draws cannot collide on the unique
            // reference; onDate() produces the real, unsuffixed form.
            'draw_reference' => WeeklyLotteryDraw::buildReference($iso).'-'.Str::upper(Str::random(4)),
            'draw_date' => $iso,
            'draw_year' => (int) $date->format('Y'),
            'draw_timezone' => (string) $timezone,
            'result_status' => WeeklyLotteryDraw::RESULT_UNAVAILABLE,
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
            'result_status' => WeeklyLotteryDraw::RESULT_PUBLISHED,
            'publication_status' => DrawPublicationStatus::Published,
            'published_at' => now(),
            'source_state' => GloSourceState::InternalReconciled->value,
        ]);
    }

    /** A published draw that published no numbers at all. */
    public function withoutNumbers(): static
    {
        return $this->state(fn (array $attributes): array => [
            'result_status' => WeeklyLotteryDraw::RESULT_UNAVAILABLE,
            'publication_status' => DrawPublicationStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function onDate(string $isoDate): static
    {
        return $this->state(fn (array $attributes): array => [
            'draw_date' => $isoDate,
            'draw_year' => (int) substr($isoDate, 0, 4),
            'draw_reference' => WeeklyLotteryDraw::buildReference($isoDate),
        ]);
    }
}
