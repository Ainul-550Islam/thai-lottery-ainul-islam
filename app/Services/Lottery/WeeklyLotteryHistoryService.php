<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\WeeklyLotteryDraw;
use App\Services\Lottery\Support\AbstractLotteryHistoryService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * Weekly year navigation and paginated history
 * (PROMPT 6; generalised in PROMPT 8).
 *
 * The year list is still a SELECT DISTINCT over stored draws, history is still
 * bounded and indexed, and only the default page size is cached - all in
 * AbstractLotteryHistoryService, shared with the Bingo/Mega lane.
 */
final class WeeklyLotteryHistoryService extends AbstractLotteryHistoryService
{
    public function __construct(
        ConfigRepository $config,
        CacheRepository $cache,
        WeeklyLotteryDateService $dates,
        WeeklyLotteryResultService $results,
    ) {
        parent::__construct($config, $cache, $dates, $results);
    }

    protected function configPrefix(): string
    {
        return 'weekly_lottery';
    }

    /**
     * @return Builder<WeeklyLotteryDraw>
     */
    protected function drawQuery(): Builder
    {
        return WeeklyLotteryDraw::query();
    }
}
