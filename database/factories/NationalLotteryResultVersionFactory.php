<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GloSourceState;
use App\Enums\ResultVersionState;
use App\Models\NationalLotteryDraw;
use App\Models\NationalLotteryResultVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NationalLotteryResultVersion>
 *
 * Defaults to the FIXTURE lane, because that is the only lane a factory is
 * entitled to produce: a factory row has no external provider behind it, so
 * calling it OFFICIAL_SOURCE_VERIFIED would be the exact mislabelling this
 * wave forbids.
 */
class NationalLotteryResultVersionFactory extends Factory
{
    protected $model = NationalLotteryResultVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $seed = (string) Str::uuid();

        return [
            'uuid' => (string) Str::uuid(),
            'draw_id' => NationalLotteryDraw::factory(),
            'version_number' => 1,
            'state' => ResultVersionState::Pending,
            'provider' => 'fixture',
            'source_state' => GloSourceState::FixtureOnly->value,
            'source_identifier' => 'NATIONAL_FIXTURE_V1',
            'source_endpoint_host' => null,
            'payload_fingerprint' => hash('sha256', 'payload:'.$seed),
            'normalized_fingerprint' => hash('sha256', 'normalized:'.$seed),
            'parser_version' => '1',
            'retrieved_at' => now(),
            'imported_at' => now(),
            'imported_by' => null,
            'supersedes_version_id' => null,
            'supersedes_version_number' => null,
            'conflict_reason' => null,
            'resolution_reason' => null,
            'resolved_by' => null,
            'resolved_at' => null,
            'validation_status' => NationalLotteryResultVersion::VALIDATION_PASSED,
            'validation_errors' => null,
            'audit' => ['origin' => 'factory'],
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => ResultVersionState::Verified,
        ]);
    }

    public function conflicted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => ResultVersionState::Conflict,
            'conflict_reason' => 'NORMALIZED_FINGERPRINT_MISMATCH',
        ]);
    }
}
