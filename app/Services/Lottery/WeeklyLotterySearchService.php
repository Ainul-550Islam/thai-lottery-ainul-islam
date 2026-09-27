<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\WeeklyLotteryDraw;
use App\Services\Lottery\Support\AbstractLotterySearchService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * Public Weekly result search (PROMPT 6; generalised in PROMPT 8).
 *
 * The type is still explicit and never guessed from length, the term is still
 * compared as a string, results are never cached and the closed type map still
 * gates every column name - all in AbstractLotterySearchService, shared with
 * the Bingo/Mega lane.
 */
final class WeeklyLotterySearchService extends AbstractLotterySearchService
{
    public function __construct(
        ConfigRepository $config,
        WeeklyLotteryDateService $dates,
        WeeklyLotteryResultService $results,
    ) {
        parent::__construct($config, $dates, $results);
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
