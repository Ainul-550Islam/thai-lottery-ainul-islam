<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\WeeklyLotteryDraw;
use App\Services\Lottery\Support\AbstractLotteryResultService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which Weekly result the public may see (PROMPT 6; generalised in PROMPT 8).
 *
 * Every rule - current-draw selection by date and publication state rather
 * than MAX(id), the cache key shape, the unavailable projection, the
 * publishable source states - lives in AbstractLotteryResultService, which the
 * Bingo/Mega lane shares. This class supplies only the config file to read and
 * the table to read it from.
 */
final class WeeklyLotteryResultService extends AbstractLotteryResultService
{
    public function __construct(
        ConfigRepository $config,
        CacheRepository $cache,
        WeeklyLotteryDateService $dates,
        WeeklyLotterySourceService $sources,
    ) {
        parent::__construct($config, $cache, $dates, $sources);
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
