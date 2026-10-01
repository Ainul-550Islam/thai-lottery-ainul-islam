<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\WeeklyLotteryDraw;
use App\Models\WeeklyLotteryResult;
use App\Models\WeeklyLotteryResultVersion;

/**
 * Unified Weekly Lottery Service Orchestrator.
 */
class WeeklyLotteryService
{
    public function __construct(
        public readonly WeeklyLotteryResultService $results,
        public readonly WeeklyLotteryDateService $dates,
        public readonly WeeklyLotteryHistoryService $history,
        public readonly WeeklyLotterySearchService $search,
        public readonly WeeklyLotterySourceService $sources,
        public readonly WeeklyLotteryImportService $importer,
        public readonly WeeklyResultIntegrityService $integrity,
    ) {
    }

    public function latestResult(): ?WeeklyLotteryResult
    {
        return $this->results->latestResult();
    }

    public function findDraw(string $drawReference): ?WeeklyLotteryDraw
    {
        return $this->results->findDraw($drawReference);
    }

    public function archiveForYear(int $buddhistYear): array
    {
        return $this->history->archiveForYear($buddhistYear);
    }
}
