<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\PcsoLotteryDraw;
use App\Services\Lottery\Support\AbstractLotteryHistoryService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * PCSO year navigation and paginated history (PROMPT 9).
 *
 * The year list is a SELECT DISTINCT over stored draws - there is no array of
 * years anywhere in this lane, so a year link can only exist if a draw exists.
 * History is bounded and indexed, and only the default page size is cached.
 * All of it lives in AbstractLotteryHistoryService, shared with the Weekly
 * lane.
 */
final class PcsoLotteryHistoryService extends AbstractLotteryHistoryService
{
    public function __construct(
        ConfigRepository $config,
        CacheRepository $cache,
        PcsoLotteryDateService $dates,
        PcsoLotteryResultService $results,
    ) {
        parent::__construct($config, $cache, $dates, $results);
    }

    protected function configPrefix(): string
    {
        return 'pcso_lottery';
    }

    /**
     * @return Builder<PcsoLotteryDraw>
     */
    protected function drawQuery(): Builder
    {
        return PcsoLotteryDraw::query();
    }
}
