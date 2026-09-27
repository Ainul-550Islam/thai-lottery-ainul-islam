<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Support\AbstractLotteryResult;

/**
 * The 6 Mega / 3 Mega / 2 Mega of one Bingo/Mega result version (PROMPT 8).
 *
 * The string-safety rules, the null-is-not-zero rule and the immutability
 * backstop all live in AbstractLotteryResult, shared with the Weekly lane.
 * This class supplies the three column names and their documented widths, and
 * the named accessors the Mega views and tests call.
 *
 * '001234', '049' and '09' round-trip as those exact strings. There is no
 * cast, accessor or helper here that performs arithmetic on a result value:
 * Number('001234') is 1234, which is a different result.
 */
class BingoLotteryResult extends AbstractLotteryResult
{
    protected $table = 'bingo_lottery_results';

    protected $fillable = [
        'draw_id',
        'result_version_id',
        'first_6_mega',
        'three_mega',
        'two_mega',
        'result_status',
        'is_current',
    ];

    /**
     * @return array<string, int>
     */
    public static function valueColumns(): array
    {
        return [
            'first_6_mega' => 6,
            'three_mega' => 3,
            'two_mega' => 2,
        ];
    }

    /**
     * @return class-string<BingoLotteryDraw>
     */
    protected static function drawClass(): string
    {
        return BingoLotteryDraw::class;
    }

    /**
     * @return class-string<BingoLotteryResultVersion>
     */
    protected static function versionClass(): string
    {
        return BingoLotteryResultVersion::class;
    }

    /** Exactly six characters, zeros intact — or null when nothing was published. */
    public function firstSixMega(): ?string
    {
        return $this->value('first_6_mega');
    }

    /** Exactly three characters, zeros intact — or null. */
    public function threeMega(): ?string
    {
        return $this->value('three_mega');
    }

    /** Exactly two characters, zeros intact — or null. */
    public function twoMega(): ?string
    {
        return $this->value('two_mega');
    }
}
