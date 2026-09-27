<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Support\AbstractLotteryDraw;

/**
 * One Weekly Lottery draw (PROMPT 6; generalised in PROMPT 8).
 *
 * Every rule about dates, references, route keys, publication and the
 * "unavailable is not zero" distinction lives in AbstractLotteryDraw, which
 * the Bingo/Mega lane shares. What remains here is only what is genuinely
 * Weekly: its table, its reference prefix, and the two models it relates to.
 *
 * SEPARATE PRODUCT LANE. This is not App\Models\Draw (operator betting), not a
 * Glo* model, and not NationalLotteryDraw. It owns no tickets, no stakes and
 * no payouts.
 */
class WeeklyLotteryDraw extends AbstractLotteryDraw
{
    protected $table = 'weekly_lottery_draws';

    protected static function referencePrefix(): string
    {
        return 'WK';
    }

    /**
     * @return class-string<WeeklyLotteryResult>
     */
    protected static function resultClass(): string
    {
        return WeeklyLotteryResult::class;
    }

    /**
     * @return class-string<WeeklyLotteryResultVersion>
     */
    protected static function versionClass(): string
    {
        return WeeklyLotteryResultVersion::class;
    }
}
