<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two lottery families the canonical discount matrix covers
 * (PROMPT 2, benchmark section F).
 *
 * THE PUBLIC MATRIX FAMILIES. National (the state 6-digit lottery's
 * game family) and Bangkok Weekly (the weekly ball games). The case
 * order here is the published section order of the public matrix page.
 */
enum DiscountLottery: string
{
    case National = 'national';
    case BangkokWeekly = 'bangkok_weekly';

    /**
     * Stable translation key for the public pages. The keys live in the
     * existing prize_discount language files (the discount-page domain),
     * so no parallel translation namespace exists to drift.
     */
    public function labelKey(): string
    {
        return match ($this) {
            self::National => 'prize_discount.matrix_lottery_national',
            self::BangkokWeekly => 'prize_discount.matrix_lottery_bangkok_weekly',
        };
    }
}
