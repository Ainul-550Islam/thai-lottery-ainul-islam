<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\BingoLotteryDraw;
use App\Services\Lottery\Support\AbstractLotteryResultService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which Mega result the public may see (PROMPT 8).
 *
 * Every rule - current-draw selection by draw DATE and publication state
 * rather than MAX(id), the cache key shape, the unavailable projection, the
 * closed list of publishable source states - lives in
 * AbstractLotteryResultService, shared with the Weekly lane. This class
 * supplies only the config file to read and the table to read it from.
 */
final class BingoLotteryResultService extends AbstractLotteryResultService
{
    public function __construct(
        ConfigRepository $config,
        CacheRepository $cache,
        BingoLotteryDateService $dates,
        BingoLotterySourceService $sources,
    ) {
        parent::__construct($config, $cache, $dates, $sources);
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
