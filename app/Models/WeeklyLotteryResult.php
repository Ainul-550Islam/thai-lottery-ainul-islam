<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Support\AbstractLotteryResult;

/**
 * The 6Ball / 3Ball / 2Ball of one Weekly result version
 * (PROMPT 6; generalised in PROMPT 8).
 *
 * The string-safety rules, the null-is-not-zero rule and the immutability
 * backstop all live in AbstractLotteryResult. This class supplies the three
 * column names and their documented widths, and keeps the named accessors the
 * Weekly views and tests already call.
 */
class WeeklyLotteryResult extends AbstractLotteryResult
{
    protected $table = 'weekly_lottery_results';

    protected $fillable = [
        'draw_id',
        'result_version_id',
        'first_6',
        'three_ball',
        'two_ball',
        'result_status',
        'is_current',
    ];

    /**
     * @return array<string, int>
     */
    public static function valueColumns(): array
    {
        return [
            'first_6' => 6,
            'three_ball' => 3,
            'two_ball' => 2,
        ];
    }

    /**
     * @return class-string<WeeklyLotteryDraw>
     */
    protected static function drawClass(): string
    {
        return WeeklyLotteryDraw::class;
    }

    /**
     * @return class-string<WeeklyLotteryResultVersion>
     */
    protected static function versionClass(): string
    {
        return WeeklyLotteryResultVersion::class;
    }

    /** Exactly six characters, zeros intact — or null when nothing was published. */
    public function firstSix(): ?string
    {
        return $this->value('first_6');
    }

    /** Exactly three characters, zeros intact — or null. */
    public function threeBall(): ?string
    {
        return $this->value('three_ball');
    }

    /** Exactly two characters, zeros intact — or null. */
    public function twoBall(): ?string
    {
        return $this->value('two_ball');
    }
}
