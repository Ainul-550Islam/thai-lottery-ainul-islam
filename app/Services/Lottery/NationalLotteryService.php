<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\NationalLotteryDraw;
use App\Models\NationalLotteryResult;
use App\Models\NationalLotteryResultVersion;

/**
 * Unified National Lottery Service Orchestrator.
 */
class NationalLotteryService
{
    public function __construct(
        public readonly NationalLotteryResultService $results,
        public readonly NationalLotteryDateService $dates,
        public readonly NationalLotteryHistoryService $history,
        public readonly NationalLotterySearchService $search,
        public readonly NationalLotterySourceService $sources,
        public readonly NationalLotteryImportService $importer,
    ) {
    }

    public function latestResult(): ?NationalLotteryResult
    {
        return $this->results->latestResult();
    }

    public function findDraw(string $drawReference): ?NationalLotteryDraw
    {
        return $this->results->findDraw($drawReference);
    }

    public function archiveForYear(int $buddhistYear): array
    {
        return $this->history->archiveForYear($buddhistYear);
    }
}
