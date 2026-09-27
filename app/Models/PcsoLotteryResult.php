<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Support\AbstractLotteryResult;

/**
 * The 6D / 4D / 3D / 2D of one PCSO result version (PROMPT 9).
 *
 * FOUR CATEGORIES, NOT THREE. This is the structural difference that made a
 * lane-defined schema necessary: Weekly and Mega publish 6/3/2, PCSO publishes
 * 6/4/3/2, and an engine that assumed three fields would have silently dropped
 * the 4D column.
 *
 * "OFF" IS NULL, AND NULL IS NOT ZERO
 * ---------------------------------------------------------------------------
 * The reference surface shows "Off" for a category a draw did not run. That is
 * stored as NULL in the column and rendered through a translated label.
 * It is never stored as 0, '0', '00', '0000' or '000000', because each of
 * those is a result a real draw could legitimately produce, and writing one to
 * mean "no draw" would invent a winning number.
 *
 * Every value is a STRING at every layer; the string-safety rules and the
 * immutability backstop live in AbstractLotteryResult, shared with the other
 * lanes.
 */
class PcsoLotteryResult extends AbstractLotteryResult
{
    protected $table = 'pcso_lottery_results';

    protected $fillable = [
        'draw_id',
        'result_version_id',
        'six_digit',
        'four_digit',
        'three_digit',
        'two_digit',
        'result_status',
        'is_current',
    ];

    /**
     * @return array<string, int>
     */
    public static function valueColumns(): array
    {
        return [
            'six_digit' => 6,
            'four_digit' => 4,
            'three_digit' => 3,
            'two_digit' => 2,
        ];
    }

    /**
     * @return class-string<PcsoLotteryDraw>
     */
    protected static function drawClass(): string
    {
        return PcsoLotteryDraw::class;
    }

    /**
     * @return class-string<PcsoLotteryResultVersion>
     */
    protected static function versionClass(): string
    {
        return PcsoLotteryResultVersion::class;
    }

    /** Exactly six characters, zeros intact — or null when this category was Off. */
    public function sixDigit(): ?string
    {
        return $this->value('six_digit');
    }

    /** Exactly four characters, zeros intact — or null when Off. */
    public function fourDigit(): ?string
    {
        return $this->value('four_digit');
    }

    /** Exactly three characters, zeros intact — or null when Off. */
    public function threeDigit(): ?string
    {
        return $this->value('three_digit');
    }

    /** Exactly two characters, zeros intact — or null when Off. */
    public function twoDigit(): ?string
    {
        return $this->value('two_digit');
    }
}
