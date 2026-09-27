<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DrawPublicationStatus;
use App\Enums\GloSourceState;
use App\Models\PcsoLotteryDraw;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PcsoLotteryDraw>
 *
 * A draw carries NO numbers, so this factory invents none. The date is
 * relative to now() rather than a hard-coded calendar date, so the fixture
 * cannot silently become "a draw in the past" as the year rolls over.
 *
 * The repository's ModelFactoryIntegrityTest requires a factory for every
 * model using HasFactory, and requires create() to persist a real row - which
 * is why this exists rather than being skipped.
 */
class PcsoLotteryDrawFactory extends Factory
{
    protected $model = PcsoLotteryDraw::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $timezone = config('pcso_lottery.timezone', 'Asia/Bangkok');
        $date = now($timezone)->subDays($this->faker->numberBetween(1, 900));
        $iso = $date->toDateString();

        return [
            'uuid' => (string) Str::uuid(),
            // Suffixed so two factory draws cannot collide on the unique
            // reference; onDate() produces the real, unsuffixed form.
            'draw_reference' => fn (array $attributes): string => PcsoLotteryDraw::buildReference(
                (string) $attributes['draw_date'],
                (string) $attributes['draw_time_local'],
            ),
            'draw_date' => $iso,

            // This lane publishes several draws per date, so a factory that
            // only varied the date would collide on the (date, time) unique
            // index as soon as it made two rows. The times are the ones the
            // lane realistically schedules, cycled rather than randomised so
            // a failing test is reproducible.
            'draw_time_local' => fake()->randomElement(['14:00', '17:00', '21:00']),
            'draw_year' => (int) $date->format('Y'),
            'draw_timezone' => (string) $timezone,
            'result_status' => PcsoLotteryDraw::RESULT_UNAVAILABLE,
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
            'result_status' => PcsoLotteryDraw::RESULT_PUBLISHED,
            'publication_status' => DrawPublicationStatus::Published,
            'published_at' => now(),
            'source_state' => GloSourceState::InternalReconciled->value,
        ]);
    }

    /** A published draw that published no numbers at all. */
    public function withoutNumbers(): static
    {
        return $this->state(fn (array $attributes): array => [
            'result_status' => PcsoLotteryDraw::RESULT_UNAVAILABLE,
            'publication_status' => DrawPublicationStatus::Published,
            'published_at' => now(),
        ]);
    }

    /**
     * Pin a draw to a date, and optionally to one of its times.
     *
     * The reference is rebuilt from BOTH, because in this lane a reference
     * built from the date alone would be the same string for the 14:00 and
     * the 21:00 draw and the second one would collide on the unique index.
     */
    public function onDate(string $isoDate, ?string $localTime = null): static
    {
        return $this->state(function (array $attributes) use ($isoDate, $localTime): array {
            $time = $localTime ?? (string) ($attributes['draw_time_local'] ?? '14:00');

            return [
                'draw_date' => $isoDate,
                'draw_time_local' => $time,
                'draw_year' => (int) substr($isoDate, 0, 4),
                'draw_reference' => PcsoLotteryDraw::buildReference($isoDate, $time),
            ];
        });
    }

    /** Pin a draw to one of the lane's scheduled times. */
    public function atTime(string $localTime): static
    {
        return $this->state(fn (array $attributes): array => [
            'draw_time_local' => $localTime,
            'draw_reference' => PcsoLotteryDraw::buildReference(
                (string) ($attributes['draw_date'] ?? ''),
                $localTime,
            ),
        ]);
    }
}
