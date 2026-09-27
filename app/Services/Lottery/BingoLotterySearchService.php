<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\BingoLotteryDraw;
use App\Services\Lottery\Support\AbstractLotterySearchService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * Public Mega result search (PROMPT 8).
 *
 * The type is explicit and never guessed from length, the term is compared as
 * a string so '09' never becomes 9, results are never cached, and the closed
 * type map in config/bingo_lottery.php is the only way a column name can enter
 * a query. All of it lives in AbstractLotterySearchService, shared with the
 * Weekly lane.
 */
final class BingoLotterySearchService extends AbstractLotterySearchService
{
    public function __construct(
        ConfigRepository $config,
        BingoLotteryDateService $dates,
        BingoLotteryResultService $results,
    ) {
        parent::__construct($config, $dates, $results);
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
