<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\DrawConfirmationStatus;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloResultImport;
use App\Services\Draw\DrawResultIngestionService;

/**
 * Decides whether a published draw result may reach player-facing channels.
 *
 * FAIL CLOSED. A result is trusted only when every link in the chain holds:
 *
 *   1. the draw has a publication timestamp;
 *   2. the result row belongs to that draw;
 *   3. the ingestion record is Confirmed, with a persisted maker and a
 *      different persisted checker, and a confirmation timestamp;
 *   4. the ingestion payload still hashes to the stored fingerprint, so it
 *      has not been edited after confirmation;
 *   5. the payload's first prize equals the published first prize;
 *   6. the payload came from the OFFICIAL provider, and a matching import row
 *      exists with status `imported`, the official provider and mode, and the
 *      same provider fingerprint.
 *
 * Fixture-provenance results never pass step 6. Fixture data is development
 * data and must not be presented to players as an official result.
 */
final class GloResultTrustPolicy
{
    private const OFFICIAL_PROVIDER = 'official';

    public function isBroadcastable(DrawResult $result, Draw $draw): bool
    {
        return $this->trustedPublication($result, $draw) !== null;
    }

    /**
     * @return array{draw: Draw, result: DrawResult}|null
     */
    public function trustedPublication(DrawResult $result, Draw $draw): ?array
    {
        if ($draw->result_published_at === null) {
            return null;
        }

        if ((int) $result->draw_id !== (int) $draw->getKey()) {
            return null;
        }

        $metadata = is_array($draw->metadata) ? $draw->metadata : [];
        $record = $metadata[DrawResultIngestionService::METADATA_KEY] ?? null;

        if (! is_array($record)) {
            return null;
        }

        if (($record['status'] ?? null) !== DrawConfirmationStatus::Confirmed->value) {
            return null;
        }

        $ingestedBy = (int) ($record['ingested_by'] ?? 0);
        $confirmedBy = (int) ($record['confirmed_by'] ?? 0);
        $confirmedAt = is_string($record['confirmed_at'] ?? null) ? trim($record['confirmed_at']) : '';

        if ($ingestedBy < 1 || $confirmedBy < 1 || $ingestedBy === $confirmedBy || $confirmedAt === '') {
            return null;
        }

        $payload = is_array($record['payload'] ?? null) ? $record['payload'] : null;
        $fingerprint = is_string($record['fingerprint'] ?? null) ? strtolower($record['fingerprint']) : '';

        if ($payload === null || $fingerprint === '') {
            return null;
        }

        if (! hash_equals($fingerprint, strtolower(DrawResultIngestionService::fingerprintFor($payload)))) {
            return null;
        }

        if ((string) ($payload['first_prize'] ?? '') !== (string) $result->first_prize) {
            return null;
        }

        $sourceLane = is_array($payload['source_lane'] ?? null) ? $payload['source_lane'] : [];
        $providerFingerprint = is_string($sourceLane['import_fingerprint'] ?? null)
            ? strtolower($sourceLane['import_fingerprint'])
            : '';

        if (($sourceLane['import_provider'] ?? null) !== self::OFFICIAL_PROVIDER
            || ($record['source'] ?? null) !== 'glo_import:'.self::OFFICIAL_PROVIDER
            || $providerFingerprint === '') {
            return null;
        }

        $import = GloResultImport::query()
            ->where('draw_id', (int) $draw->getKey())
            ->where('provider', self::OFFICIAL_PROVIDER)
            ->where('mode', self::OFFICIAL_PROVIDER)
            ->where('status', 'imported')
            ->where('result_fingerprint', $providerFingerprint)
            ->first();

        if ($import === null) {
            return null;
        }

        $result->loadMissing('winningNumbers');

        return ['draw' => $draw, 'result' => $result];
    }
}
