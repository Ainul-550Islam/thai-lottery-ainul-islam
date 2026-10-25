<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DataSubjectRequest;
use App\Models\PersonalDataExport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Synthetic export rows.
 *
 * The digest is of a fixed, obviously-fake payload: a factory that produced a
 * random 64-character string would look like a verified artefact while
 * matching nothing, which is precisely the confusion this column exists to
 * prevent. Tests that need a real archive build one through the exporter.
 *
 * @extends Factory<PersonalDataExport>
 */
class PersonalDataExportFactory extends Factory
{
    protected $model = PersonalDataExport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_id' => DataSubjectRequest::factory(),
            'user_id' => null,
            'disk' => 'local',
            'path' => 'privacy-exports/fixture/'.fake()->uuid().'.zip',
            'sha256' => hash('sha256', 'synthetic development export payload'),
            'size_bytes' => 2048,
            'status' => PersonalDataExport::STATUS_READY,
            'released_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays(7),
            'download_count' => 0,
            'max_downloads' => 3,
            'last_downloaded_at' => null,
        ];
    }

    public function building(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PersonalDataExport::STATUS_BUILDING,
            'released_at' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PersonalDataExport::STATUS_EXPIRED,
            'expires_at' => Carbon::now()->subDay(),
        ]);
    }
}
