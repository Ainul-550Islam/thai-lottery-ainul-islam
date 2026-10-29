<?php

declare(strict_types=1);

namespace App\Services\ResultIngestion;

use App\Exceptions\LegacyLotteryEntryException;
use App\Models\DrawResult;

/**
 * Retired legacy draft writer. It wrote directly to draw_results and could
 * replace a canonical published result. Callers must use the existing canonical
 * DrawResultIngestionService and authenticated admin confirmation workflow.
 */
class GloResultIngestionService
{
    public const AUTOMATED_IMPORTER_ID = 0;

    public function ingestPayload(
        int|string $drawId,
        array $resultPayload,
        int|string $operatorId,
        string $sourceProvider = 'GLO_OFFICIAL'
    ): DrawResult {
        throw LegacyLotteryEntryException::unavailable('result ingestion');
    }
}
