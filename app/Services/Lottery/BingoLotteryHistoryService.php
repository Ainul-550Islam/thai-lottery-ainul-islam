<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\BingoLotteryDraw;
use App\Services\Lottery\Support\AbstractLotteryHistoryService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mega year navigation and paginated history (PROMPT 8).
 *
 * The year list is a SELECT DISTINCT over stored draws - there is no array of
 * years anywhere in this lane, so a year link can only exist if a draw exists.
 * History is bounded and indexed, and only the default page size is cached.
 * All of it lives in AbstractLotteryHistoryService, shared with the Weekly
 * lane.
 */
final class BingoLotteryHistoryService extends AbstractLotteryHistoryService
{
    public function __construct(
        ConfigRepository $config,
        CacheRepository $cache,
        BingoLotteryDateService $dates,
        BingoLotteryResultService $results,
    ) {
        parent::__construct($config, $cache, $dates, $results);
    }

    protected function configPrefix(): string
    {
        return 'bingo_lottery';
    }

    /**
     * @return Builder<BingoLotteryDraw>
     */
    protected function drawQuery(): Builder
    {
        return BingoLotteryDraw::query();
    }
}
