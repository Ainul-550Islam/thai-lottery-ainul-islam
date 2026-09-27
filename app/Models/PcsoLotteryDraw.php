<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Support\AbstractLotteryDraw;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * One PCSO Lottery draw (PROMPT 9).
 *
 * SEPARATE PRODUCT LANE. Not App\Models\Draw (operator betting), not a Glo*
 * model, and not the National, Weekly or Mega draw. It owns no tickets, no
 * stakes and no payouts.
 *
 * A DATE DOES NOT IDENTIFY A PCSO DRAW
 * ---------------------------------------------------------------------------
 * This lane publishes several draws on one calendar date - 14:00, 17:00,
 * 21:00. Identity is therefore date AND local draw time, enforced by a
 * composite unique index, and the public reference carries the time so the
 * three draws on a day have three distinct URLs. Every rule that does NOT
 * depend on that difference still comes from AbstractLotteryDraw, shared with
 * the other lanes.
 *
 * draw_time_local IS A CLOCK READING, NOT AN INSTANT. It is stored as a
 * five-character string because that is what it is: the time printed on the
 * draw in Asia/Bangkok. Stored as a datetime, a driver or a server in another
 * timezone could shift a 21:00 draw onto the next day, and the result would be
 * filed under a date it did not belong to.
 *
 * @property string $draw_time_local
 */
class PcsoLotteryDraw extends AbstractLotteryDraw
{
    protected $table = 'pcso_lottery_draws';

    protected static function referencePrefix(): string
    {
        return 'PCSO';
    }

    /**
     * @return class-string<PcsoLotteryResult>
     */
    protected static function resultClass(): string
    {
        return PcsoLotteryResult::class;
    }

    /**
     * @return class-string<PcsoLotteryResultVersion>
     */
    protected static function versionClass(): string
    {
        return PcsoLotteryResultVersion::class;
    }

    /**
     * @return list<string>
     */
    public function getFillable(): array
    {
        return array_merge(parent::getFillable(), ['draw_time_local']);
    }

    /**
     * Exactly five characters, 'HH:MM', on the way in and the way out.
     *
     * No arithmetic and no timezone conversion: substr and a string are all
     * that is involved, so '09:05' cannot become '9:5' or shift an hour.
     *
     * @return Attribute<string|null, string>
     */
    protected function drawTimeLocal(): Attribute
    {
        return Attribute::make(
            get: static fn (mixed $value): ?string => $value === null || $value === ''
                ? null
                : substr((string) $value, 0, 5),
            set: static fn (mixed $value): string => substr(trim((string) $value), 0, 5),
        );
    }
}
