<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Support\AbstractLotteryDraw;

/**
 * One Bingo/Mega Lottery draw (PROMPT 8).
 *
 * SEPARATE PRODUCT LANE. Not App\Models\Draw (operator betting), not a Glo*
 * model, not NationalLotteryDraw and not WeeklyLotteryDraw. It owns a date, a
 * result status, a publication state, a provenance state and a pointer to
 * whichever version currently answers for it. It owns no tickets, no stakes
 * and no payouts.
 *
 * Every rule about dates, route keys, references and the
 * "unavailable is not zero" distinction lives in AbstractLotteryDraw, shared
 * with the Weekly lane. What is Mega's own is only its table, its reference
 * prefix and the two models it relates to.
 *
 * THE PREFIX IS 'MG', NOT 'WK'. A reference has to identify its lane on
 * sight - in a URL, a log line or an import file - or two lanes that drew on
 * the same date would produce colliding references.
 */
class BingoLotteryDraw extends AbstractLotteryDraw
{
    protected $table = 'bingo_lottery_draws';

    protected static function referencePrefix(): string
    {
        return 'MG';
    }

    /**
     * @return class-string<BingoLotteryResult>
     */
    protected static function resultClass(): string
    {
        return BingoLotteryResult::class;
    }

    /**
     * @return class-string<BingoLotteryResultVersion>
     */
    protected static function versionClass(): string
    {
        return BingoLotteryResultVersion::class;
    }
}
