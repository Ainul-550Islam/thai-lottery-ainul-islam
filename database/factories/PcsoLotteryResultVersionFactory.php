<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GloSourceState;
use App\Enums\ResultVersionState;
use App\Models\PcsoLotteryDraw;
use App\Models\PcsoLotteryResultVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PcsoLotteryResultVersion>
 *
 * Defaults to the FIXTURE lane, because that is the only lane a factory is
 * entitled to produce: a factory row has no external provider behind it, so
 * calling it OFFICIAL_SOURCE_VERIFIED would be the exact mislabelling this
 * wave forbids.
 *
 * integrity_status defaults to INTEGRITY_HASH_ONLY for the same reason - a
 * fixture row was never signed, and saying SIGNED_VERIFIED would be a false
 * assurance baked into test data.
 */
class PcsoLotteryResultVersionFactory extends Factory
{
    protected $model = PcsoLotteryResultVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $seed = (string) Str::uuid();

        return [
            'uuid' => (string) Str::uuid(),
            'draw_id' => PcsoLotteryDraw::factory(),
            'version_number' => 1,
            'state' => ResultVersionState::Pending,
            'provider' => 'fixture',
            'source_state' => GloSourceState::FixtureOnly->value,
            'source_identifier' => 'WEEKLY_FIXTURE_V1',
            'source_endpoint_host' => null,
            'payload_fingerprint' => hash('sha256', 'payload:'.$seed),
            'normalized_fingerprint' => hash('sha256', 'normalized:'.$seed),
            'parser_version' => '1',
            'integrity_status' => 'INTEGRITY_HASH_ONLY',
            'canonical_version' => 'PCSO1',
            'integrity_native_verified' => false,
            'retrieved_at' => now(),
            'imported_at' => now(),
            'imported_by' => null,
            'supersedes_version_id' => null,
            'supersedes_version_number' => null,
            'conflict_reason' => null,
            'resolution_reason' => null,
            'resolved_by' => null,
            'resolved_at' => null,
            'validation_status' => PcsoLotteryResultVersion::VALIDATION_PASSED,
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
