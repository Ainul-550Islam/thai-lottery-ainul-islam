<?php

declare(strict_types=1);

namespace Tests\Feature\Glo\Concerns;

use App\Enums\DrawConfirmationStatus;
use App\Models\Draw;
use App\Models\GloResultImport;
use App\Models\User;
use App\Services\Draw\DrawResultIngestionService;

/**
 * Builds a database fixture shaped like the persisted GLO import and
 * confirmation records consumed by GloResultTrustPolicy.
 *
 * This helper is test-only. Production result writes still pass through the
 * ingestion, confirmation and publication services.
 */
trait CreatesTrustedGloResult
{
    /**
     * Persist a fixture-provider import plus its matching Confirmed ingestion
     * record and return the result-row metadata written by publication.
     *
     * @param  array<string, list<string>>  $tiers
     * @param  array<string, list<string>>  $n3
     * @param  list<string>  $secondPrize
     * @param  list<string>  $thirdPrize
     * @return array<string, mixed>
     */
    protected function trustedFixtureResultMetadata(
        Draw $draw,
        User $maker,
        User $checker,
        string $firstPrize,
        string $bottomTwo,
        array $secondPrize = [],
        array $thirdPrize = [],
        array $tiers = [],
        array $n3 = [],
        ?string $importReference = null,
        ?string $providerFingerprint = null,
        string $provider = 'fixture',
        ?string $mode = null,
    ): array {
        $mode ??= $provider;
        $importReference ??= 'glo-'.$provider.'-'.$draw->getKey().'-'.bin2hex(random_bytes(6));
        $providerFingerprint ??= hash('sha256', json_encode([
            'draw' => (string) $draw->draw_number,
            'first_prize' => $firstPrize,
            'bottom_two' => $bottomTwo,
            'second_prize' => array_values($secondPrize),
            'third_prize' => array_values($thirdPrize),
            'tiers' => $tiers,
            'n3' => $n3,
        ], JSON_THROW_ON_ERROR));

        $sourceLane = [];

        if ($tiers !== []) {
            $sourceLane['tiers'] = $tiers;
        }

        if ($n3 !== []) {
            $sourceLane['n3'] = $n3;
        }

        $sourceLane['import_provider'] = $provider;
        $sourceLane['import_fingerprint'] = strtolower($providerFingerprint);
        $sourceLane['upstream_draw_id'] = (string) $draw->draw_number;

        $payload = [
            'first_prize' => $firstPrize,
            'bottom_two' => $bottomTwo,
            'second_prize' => array_values($secondPrize),
            'third_prize' => array_values($thirdPrize),
            'source_lane' => $sourceLane,
        ];
        $ingestionFingerprint = DrawResultIngestionService::fingerprintFor($payload);

        $drawMetadata = is_array($draw->metadata) ? $draw->metadata : [];
        $drawMetadata[DrawResultIngestionService::METADATA_KEY] = [
            'status' => DrawConfirmationStatus::Confirmed->value,
            'fingerprint' => $ingestionFingerprint,
            'source' => 'glo_import:'.$provider,
            'payload' => $payload,
            'ingested_at' => now()->subMinute()->toIso8601String(),
            'ingested_by' => (int) $maker->getKey(),
            'confirmed_at' => now()->toIso8601String(),
            'confirmed_by' => (int) $checker->getKey(),
        ];
        $draw->metadata = $drawMetadata;

        // This helper represents a published trust record, so the lifecycle
        // publication timestamp must exist as well as the result row timestamp.
        // Assign directly because lifecycle columns are intentionally not mass assignable.
        if ($draw->result_published_at === null) {
            $draw->result_published_at = now();
        }

        $draw->save();

        GloResultImport::query()->create([
            'draw_id' => (int) $draw->getKey(),
            'provider' => $provider,
            'mode' => $mode,
            'endpoint' => 'test-fixture',
            'upstream_draw_id' => (string) $draw->draw_number,
            'status' => 'imported',
            'result_fingerprint' => strtolower($providerFingerprint),
            'payload_summary' => [
                'first_prize' => $firstPrize,
                'bottom_two' => $bottomTwo,
                'second_prize_count' => count($secondPrize),
                'third_prize_count' => count($thirdPrize),
                'n3_counts' => $this->fixtureGroupCounts($n3),
                'tier_counts' => $this->fixtureGroupCounts($tiers),
            ],
            'import_reference' => $importReference,
            'imported_by' => (int) $maker->getKey(),
            'imported_at' => now()->subMinute(),
        ]);

        $gloLane = [];

        foreach ($tiers as $key => $values) {
            $gloLane[$key] = array_values($values);
        }

        if ($n3 !== []) {
            $gloLane['n3'] = $n3;
        }

        $gloLane['import_provider'] = $provider;
        $gloLane['import_fingerprint'] = strtolower($providerFingerprint);
        $gloLane['imported_at'] = now()->subMinute()->toIso8601String();
        $gloLane['lane_confirmed_by'] = (int) $checker->getKey();

        return [
            'provenance' => $provider === 'official' && $mode === 'official' ? 'source-verified' : 'fixture',
            'provenance_reference' => $importReference,
            'four_eyes' => [
                'ingested_by' => (int) $maker->getKey(),
                'confirmed_by' => (int) $checker->getKey(),
                'ingestion_fingerprint' => $ingestionFingerprint,
            ],
            'bottom_two' => $bottomTwo,
            'glo' => $gloLane,
        ];
    }

    /**
     * @param  array<string, list<string>>  $groups
     * @return array<string, int>
     */
    private function fixtureGroupCounts(array $groups): array
    {
        $counts = [];

        foreach (array_slice($groups, 0, 16, true) as $key => $values) {
            $name = mb_substr((string) $key, 0, 32);

            if ($name !== '') {
                $counts[$name] = count($values);
            }
        }

        return $counts;
    }
}
