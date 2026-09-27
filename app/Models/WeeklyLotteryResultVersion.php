<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Support\AbstractLotteryResultVersion;

/**
 * Immutable provenance for one attempt to state a Weekly draw's result
 * (PROMPT 6; generalised in PROMPT 8).
 *
 * The immutability guard, the deletion refusal and every
 * ProvidesResultProvenance accessor live in AbstractLotteryResultVersion. Only
 * the table and the two related models are Weekly's own.
 */
class WeeklyLotteryResultVersion extends AbstractLotteryResultVersion
{
    protected $table = 'weekly_lottery_result_versions';

    /**
     * @return class-string<WeeklyLotteryDraw>
     */
    protected static function drawClass(): string
    {
        return WeeklyLotteryDraw::class;
    }

    /**
     * @return class-string<WeeklyLotteryResult>
     */
    protected static function resultClass(): string
    {
        return WeeklyLotteryResult::class;
    }
}
