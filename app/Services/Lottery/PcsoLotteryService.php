<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\PcsoLotteryDraw;
use App\Models\PcsoLotteryResult;
use App\Models\PcsoLotteryResultVersion;

/**
 * Unified PCSO Lottery Service Orchestrator.
 */
class PcsoLotteryService
{
    public function __construct(
        public readonly PcsoLotteryResultService $results,
        public readonly PcsoLotteryDateService $dates,
        public readonly PcsoLotteryHistoryService $history,
        public readonly PcsoLotterySearchService $search,
        public readonly PcsoLotterySourceService $sources,
        public readonly PcsoLotteryImportService $importer,
    ) {
    }

    public function latestResult(): ?PcsoLotteryResult
    {
        return $this->results->latestResult();
    }

    public function findDraw(string $drawReference): ?PcsoLotteryDraw
    {
        return $this->results->findDraw($drawReference);
    }

    public function archiveForYear(int $buddhistYear): array
    {
        return $this->history->archiveForYear($buddhistYear);
    }
}
