<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Services\Lottery\BingoLotteryResultService;
use App\Services\Lottery\NationalLotteryResultService;
use App\Services\Lottery\PcsoLotteryResultService;
use App\Services\Lottery\WeeklyLotteryResultService;
use App\Services\Lottery\GloResultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Universal Lottery Result API Controller.
 *
 * Provides standardized, provenance-tagged results across all lottery lanes.
 */
class ResultController
{
    public function __construct(
        private readonly NationalLotteryResultService $national,
        private readonly WeeklyLotteryResultService $weekly,
        private readonly BingoLotteryResultService $bingo,
        private readonly PcsoLotteryResultService $pcso,
        private readonly GloResultService $glo,
    ) {
    }

    /**
     * Retrieve the latest published result for a given lottery lane.
     */
    public function latest(string $lane): JsonResponse
    {
        $laneKey = strtolower(trim($lane));

        return match ($laneKey) {
            'national' => $this->formatResult($this->national->latestResult(), 'national'),
            'weekly' => $this->formatResult($this->weekly->latestResult(), 'weekly'),
            'bingo', 'mega' => $this->formatResult($this->bingo->latestResult(), 'bingo'),
            'pcso' => $this->formatResult($this->pcso->latestResult(), 'pcso'),
            default => ApiResponse::error('unsupported_lane', "Lottery lane [{$laneKey}] is not supported.", 404),
        };
    }

    private function formatResult($result, string $lane): JsonResponse
    {
        if ($result === null) {
            return ApiResponse::error('result_not_found', "No published result found for [{$lane}].", 404);
        }

        return ApiResponse::success([
            'lane' => $lane,
            'draw_date' => $result->draw_date ?? null,
            'first_prize' => $result->first_prize ?? null,
            'result_data' => $result->toArray(),
        ], "Latest [{$lane}] result retrieved successfully.");
    }
}
