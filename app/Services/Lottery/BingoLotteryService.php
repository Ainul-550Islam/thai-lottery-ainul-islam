<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\BingoLotteryDraw;
use App\Models\BingoLotteryResult;
use App\Models\BingoLotteryResultVersion;

/**
 * Unified Bingo/Mega Lottery Service Orchestrator.
 */
class BingoLotteryService
{
    public function __construct(
        public readonly BingoLotteryResultService $results,
        public readonly BingoLotteryDateService $dates,
        public readonly BingoLotteryHistoryService $history,
        public readonly BingoLotterySearchService $search,
        public readonly BingoLotterySourceService $sources,
        public readonly BingoLotteryImportService $importer,
    ) {
    }

    public function latestResult(): ?BingoLotteryResult
    {
        return $this->results->latestResult();
    }

    public function findDraw(string $drawReference): ?BingoLotteryDraw
    {
        return $this->results->findDraw($drawReference);
    }

    public function archiveForYear(int $buddhistYear): array
    {
        return $this->history->archiveForYear($buddhistYear);
    }
}
