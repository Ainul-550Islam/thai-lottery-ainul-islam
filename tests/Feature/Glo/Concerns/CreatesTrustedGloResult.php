<?php

declare(strict_types=1);

namespace Tests\Feature\Glo\Concerns;

use App\Enums\DrawConfirmationStatus;
use App\Models\Draw;
use App\Models\DrawResult;
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
        $expectedProviderFingerprint = hash('sha256', json_encode([
            (string) $draw->draw_number,
            $firstPrize,
            array_values($secondPrize),
            array_values($thirdPrize),
            $n3,
            $tiers,
        ], JSON_THROW_ON_ERROR));

        $providerFingerprint ??= $expectedProviderFingerprint;
        $providerFingerprint = strtolower($providerFingerprint);

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
        $ingestedAt = now()->subMinute()->toIso8601String();

        $drawMetadata = is_array($draw->metadata) ? $draw->metadata : [];
        $drawMetadata[DrawResultIngestionService::METADATA_KEY] = [
            'status' => DrawConfirmationStatus::Confirmed->value,
            'fingerprint' => $ingestionFingerprint,
            'source' => 'glo_import:'.$provider,
            'payload' => $payload,
            'ingested_at' => $ingestedAt,
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
                'draw_number' => (string) $draw->draw_number,
                'first_prize' => $firstPrize,
                'bottom_two' => $bottomTwo,
                'second_prize_count' => count($secondPrize),
                'third_prize_count' => count($thirdPrize),
                'n3_counts' => $this->fixtureGroupCounts($n3),
                'tier_counts' => $this->fixtureGroupCounts($tiers),
                'provider_fingerprint' => strtolower($providerFingerprint),
                'ingestion_fingerprint' => $ingestionFingerprint,
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
        $gloLane['imported_at'] = $ingestedAt;
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
     * Complete the fixture's confirmation link after its result row is inserted.
     *
     * Production writes this link inside DrawResultConfirmationService's
     * transaction. The test helper creates the two persisted records separately,
     * so it must explicitly reproduce that final cross-reference.
     */
    protected function linkTrustedDrawResult(Draw $draw, DrawResult $result): void
    {
        $metadata = is_array($draw->metadata) ? $draw->metadata : [];
        $record = $metadata[DrawResultIngestionService::METADATA_KEY] ?? null;

        if (! is_array($record)) {
            throw new \LogicException('A trusted result fixture requires its confirmed ingestion record.');
        }

        $record['draw_result_id'] = (int) $result->getKey();
        $metadata[DrawResultIngestionService::METADATA_KEY] = $record;
        $draw->metadata = $metadata;
        $draw->save();
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
