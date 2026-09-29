<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The canonical discount-game space (PROMPT 2, benchmark section F).
 *
 * THE NEW CANONICAL GAME SPACE. These 18 keys are the games the public
 * grade/discount matrix speaks about: 10 national-lottery games and 8
 * Bangkok-weekly games. They are DISTINCT from the live traced product
 * codes (operator_3d/2d/run and the GLO lanes): the live bet economics
 * keep their existing contracts untouched, and the matrix/quote surface
 * speaks ONLY these keys. No invented product<->game mapping exists.
 *
 * GLO IS STRUCTURALLY ABSENT. The government-priced lanes are not -
 * and cannot be - matrix games, so the discount programme has no way to
 * express a reduction on them.
 *
 * ORDER IS CONTRACT. publicGames() is the canonical published order:
 * national family first, then Bangkok weekly, each in draw-regular
 * order followed by its single-multiplier variants. Entitlement lists,
 * API rows and rendered panels all inherit this order so the same game
 * never appears in two places in two orders.
 */
enum DiscountGame: string
{
    // ---- National lottery -------------------------------------------------

    case NationalSixDigit = 'national_six_digit';
    case National3Up = 'national_3_up';
    case National2Up = 'national_2_up';
    case National2Down = 'national_2_down';
    case National1Of3UpSingleDigit = 'national_1_of_3_up_single_digit';
    case National1Of2UpSingleDigit = 'national_1_of_2_up_single_digit';
    case National1Of2DownSingleDigit = 'national_1_of_2_down_single_digit';
    case National3UpGameTotal = 'national_3_up_game_total';
    case National2UpGameTotal = 'national_2_up_game_total';
    case National2DownGameTotal = 'national_2_down_game_total';

    // ---- Bangkok weekly lottery -------------------------------------------

    case Weekly6Ball = 'weekly_6_ball';
    case Weekly3Ball = 'weekly_3_ball';
    case Weekly2Ball = 'weekly_2_ball';
    case Weekly1Of3BallSingleDigit = 'weekly_1_of_3_ball_single_digit';
    case Weekly1Of2BallSingleDigit = 'weekly_1_of_2_ball_single_digit';
    case Weekly6BallGameTotal = 'weekly_6_ball_game_total';
    case Weekly3BallGameTotal = 'weekly_3_ball_game_total';
    case Weekly2BallGameTotal = 'weekly_2_ball_game_total';

    /**
     * Every public matrix game, in canonical published order.
     *
     * @return list<self>
     */
    public static function publicGames(): array
    {
        return [
            self::NationalSixDigit,
            self::National3Up,
            self::National2Up,
            self::National2Down,
            self::National1Of3UpSingleDigit,
            self::National1Of2UpSingleDigit,
            self::National1Of2DownSingleDigit,
            self::National3UpGameTotal,
            self::National2UpGameTotal,
            self::National2DownGameTotal,
            self::Weekly6Ball,
            self::Weekly3Ball,
            self::Weekly2Ball,
            self::Weekly1Of3BallSingleDigit,
            self::Weekly1Of2BallSingleDigit,
            self::Weekly6BallGameTotal,
            self::Weekly3BallGameTotal,
            self::Weekly2BallGameTotal,
        ];
    }

    /**
     * The lottery family this game belongs to.
     */
    public function lottery(): DiscountLottery
    {
        return str_starts_with($this->value, 'national_')
            ? DiscountLottery::National
            : DiscountLottery::BangkokWeekly;
    }

    /**
     * Does this game quote in the two draw-regular directions D/R?
     *
     * The seven headline games carry a direct ('D') and a reverse ('R')
     * win multiplier. The single-multiplier variants (running digits and
     * game totals) publish ONE multiplier and are NOT_CONFIGURED for a
     * game discount percentage.
     */
    public function hasDrawRegularModes(): bool
    {
        return match ($this) {
            self::NationalSixDigit,
            self::National3Up,
            self::National2Up,
            self::National2Down,
            self::Weekly6Ball,
            self::Weekly3Ball,
            self::Weekly2Ball => true,
            default => false,
        };
    }

    /**
     * Stable translation key for the public pages. The keys live in the
     * existing prize_discount language files (the discount-page domain),
     * so no parallel translation namespace exists to drift.
     */
    public function labelKey(): string
    {
        return 'prize_discount.matrix_game_'.$this->value;
    }
}
