<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\DTOs\Account\GradeDiscountEntitlement;
use App\DTOs\Lottery\DiscountRule;

/**
 * HOW a grade discount combines with a game rule (PROMPT 2, section H).
 *
 * THE LADDER IS ADDITIVE, LAYER BY LAYER, ON MONEY:
 *
 *   Layer 1 - the published game rule. The game's configured percentage
 *   applied to the gross stake. A NOT_CONFIGURED game row contributes
 *   no game layer: an unspecified percentage is a state, never a
 *   guessed number.
 *
 *   Layer 2 - the grade entitlement. The player's grade rate applied
 *   to WHAT REMAINS of the stake after layer 1, clamped to that
 *   remainder. Each layer is applied exactly once; a non-entitled
 *   grade contributes exactly 0.00 and says NOT_ELIGIBLE explicitly.
 *
 *   Ceiling - the combined total is capped at
 *   discounts.limits.max_total_percentage (default 60) of the gross
 *   stake, mirroring the traced engine's total-percentage ceiling.
 *
 * The combined percentage is always DERIVED FROM THE MONEY (total /
 * gross), never by adding the two rate percentages: adding 35% + 6% of
 * the ORIGINAL stake is exactly the float shortcut this policy exists
 * to prevent.
 *
 * EVERY FIGURE IS A DECIMAL STRING. Rounding is half-up at two
 * decimals, implemented with string bcmath - no binary float ever
 * touches a money value here.
 */
final class GradeDiscountApplicationPolicy
{
    public const MODE_STACKED = 'STACKED';

    public const MODE_GAME_ONLY = 'GAME_ONLY';

    public const MODE_GRADE_ONLY = 'GRADE_ONLY';

    public const MODE_NONE = 'NONE';

    private const SCALE = 2;

    /**
     * Resolve the combined application of one game rule and one grade
     * entitlement to a gross stake.
     *
     * @return array{
     *     mode: string,
     *     game_discount_percent: string|null,
     *     game_discount: string,
     *     grade_discount_percent: string,
     *     grade_discount: string,
     *     total_discount: string,
     *     net_stake: string,
     *     grade_state: string,
     *     note: string
     * }
     */
    public function resolve(DiscountRule $rule, GradeDiscountEntitlement $entitlement, string $grossStake): array
    {
        $scale = self::SCALE;
        $base = bcadd($grossStake, '0', $scale);

        if (bccomp($base, '0', $scale) < 0) {
            throw new \InvalidArgumentException('Gross stake must not be negative.');
        }

        // ---- Layer 1: the published game discount -----------------------
        // A NOT_CONFIGURED row contributes no percentage-driven discount:
        // an unspecified percentage is a state, never a guessed number.
        $gamePercent = $rule->isDiscountConfigured() ? $rule->discount : null;
        $gameDiscount = '0.00';

        if ($gamePercent !== null) {
            $rate = bcdiv($gamePercent, '100', 6);
            $gameDiscount = $this->roundHalfUp(bcmul($base, $rate, 6), $scale);
        }

        $gameDiscount = $this->clamp($gameDiscount, $base, '0.00', $scale);

        // ---- Layer 2: the grade discount (the additional benefit) -------
        // Clamped against what remains of the stake after the game layer,
        // exactly like the engine's clampAgainstRemaining().
        $gradePercent = '0.00';
        $gradeDiscount = '0.00';

        if ($entitlement->isEligible()) {
            $gradePercent = $entitlement->ratePercent();
            $remaining = bcsub($base, $gameDiscount, $scale);
            $gradeDiscount = $this->roundHalfUp(bcmul($remaining, $entitlement->rate, 6), $scale);
            $gradeDiscount = $this->clamp($gradeDiscount, $remaining, '0.00', $scale);
        }

        // ---- The global ceiling (traced engine invariant) ----------------
        $total = bcadd($gameDiscount, $gradeDiscount, $scale);
        $total = $this->applyTotalCeiling($base, $total, $scale);

        $net = bcsub($base, $total, $scale);
        $net = bccomp($net, '0', $scale) < 0 ? '0.00' : $net;

        $mode = match (true) {
            bccomp($gameDiscount, '0', $scale) > 0 && bccomp($gradeDiscount, '0', $scale) > 0 => self::MODE_STACKED,
            bccomp($gameDiscount, '0', $scale) > 0 => self::MODE_GAME_ONLY,
            bccomp($gradeDiscount, '0', $scale) > 0 => self::MODE_GRADE_ONLY,
            default => self::MODE_NONE,
        };

        return [
            'mode' => $mode,
            'game_discount_percent' => $gamePercent,
            'game_discount' => $gameDiscount,
            'grade_discount_percent' => $gradePercent,
            'grade_discount' => $gradeDiscount,
            'total_discount' => $total,
            'net_stake' => $net,
            'grade_state' => $entitlement->state,
            'note' => $this->note($mode, $gamePercent, $entitlement),
        ];
    }

    /**
     * Cap the combined discount at the configured total-percentage
     * ceiling. The default (60) mirrors the traced engine invariant;
     * the ceiling can never exceed the stake itself.
     */
    private function applyTotalCeiling(string $base, string $total, int $scale): string
    {
        $ceilingPercent = bcadd((string) config('discounts.limits.max_total_percentage', '60'), '0', 2);

        $ceiling = $this->roundHalfUp(bcmul($base, bcdiv($ceilingPercent, '100', 6), 6), $scale);
        $ceiling = $this->clamp($ceiling, $base, '0.00', $scale);

        return bccomp($total, $ceiling, $scale) > 0 ? $ceiling : $total;
    }

    /**
     * The human-readable reason key for the resolved mode.
     */
    private function note(string $mode, ?string $gamePercent, GradeDiscountEntitlement $entitlement): string
    {
        return match ($mode) {
            self::MODE_STACKED => 'grade_discount_stacked_on_game_rule',
            self::MODE_GAME_ONLY => $entitlement->isEligible()
                ? 'game_rule_only_grade_layer_zero'
                : 'grade_not_entitled_game_only',
            self::MODE_GRADE_ONLY => 'grade_only_no_game_percentage',
            default => 'no_discount',
        };
    }

    /**
     * Clamp a value into [min, max] with bcmath.
     */
    private function clamp(string $value, string $max, string $min, int $scale): string
    {
        if (bccomp($value, $min, $scale) < 0) {
            return bcadd($min, '0', $scale);
        }

        if (bccomp($value, $max, $scale) > 0) {
            return bcadd($max, '0', $scale);
        }

        return bcadd($value, '0', $scale);
    }

    /**
     * Half-up rounding at the target scale, on decimal strings.
     *
     * bcmath truncates at the result scale, so the digit just past the
     * scale decides the correction: >= 5 bumps the last kept digit,
     * anything less leaves it. This is the string-only equivalent of
     * the engine's money rounding.
     */
    private function roundHalfUp(string $value, int $scale): string
    {
        $truncated = bcadd($value, '0', $scale);

        $remainder = bcsub($value, $truncated, $scale + 4);

        // Negative inputs never occur in this policy (stakes and
        // discounts are non-negative); guard anyway.
        if (bccomp($remainder, '0', $scale + 4) < 0) {
            return $truncated;
        }

        $halfUnit = '0.'.str_repeat('0', $scale).'5';

        if (bccomp($remainder, $halfUnit, $scale + 4) >= 0) {
            $truncated = bcadd($truncated, '0.'.str_repeat('0', max(0, $scale - 1)).'1', $scale);
        }

        return $truncated;
    }
}
