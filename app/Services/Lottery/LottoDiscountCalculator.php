<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\DTOs\Account\GradeTier;
use App\DTOs\Lottery\DiscountRule;
use App\Enums\DiscountGame;
use App\Models\User;
use App\Services\Account\GradeDiscountApplicationPolicy;
use App\Services\Account\GradeDiscountEntitlementService;
use App\Services\Account\GradeTierCatalog;
use InvalidArgumentException;

/**
 * The canonical matrix quote calculator (PROMPT 2, sections G+H).
 *
 * WHAT IT IS. The single calculator behind the matrix/quote surface:
 * given a matrix game, a gross stake and (for headline games) a D/R
 * direction, it returns the published win multiplier, the projected
 * payout, and the combined discount resolved through the explicit
 * grade→game application policy.
 *
 * WHAT IT IS NOT. It is NOT the live bet economics. The traced betting
 * purchase path keeps its own services and contracts (GLO immutable
 * pricing, max_discount_rate caps); nothing here is called from the
 * bet placement flow. This class serves the public matrix surface and
 * the per-user quote surface only.
 *
 * SERVER-SIDE ONLY. The stake arrives as a validated decimal string
 * (the same shape StrictMoneyAmount enforces at the HTTP boundary),
 * the grade is resolved from the user's REAL tier via the evaluator's
 * spend source, and the combined percentage is always DERIVED FROM THE
 * MONEY - never by adding rate percentages.
 *
 * EVERY FIGURE IS A DECIMAL STRING. Rounding is half-up at two
 * decimals with string bcmath.
 */
final class LottoDiscountCalculator
{
    private const SCALE = 2;

    public function __construct(
        private readonly GradeTierCatalog $catalog,
        private readonly CanonicalDiscountMatrixService $matrix,
        private readonly GradeDiscountEntitlementService $entitlements,
        private readonly GradeDiscountApplicationPolicy $policy,
    ) {}

    /**
     * Calculate a quote for one matrix game.
     *
     * @param  DiscountGame  $game  matrix game key
     * @param  string  $grossStake  plain decimal string, scale <= 2
     * @param  string|null  $mode  'D' | 'R' for headline games, null for variants
     * @param  User|null  $user  whose grade entitlement applies (null = none)
     * @param  \DateTimeInterface|null  $asOf  pin the rule instant
     *
     * @return array<string, mixed>
     */
    public function calculate(
        DiscountGame $game,
        string $grossStake,
        ?string $mode = null,
        ?User $user = null,
        ?\DateTimeInterface $asOf = null,
    ): array {
        $base = $this->normalizeStake($grossStake);

        $rule = $this->matrix->ruleFor($game, $asOf);

        if ($rule === null || ! $rule->enabled) {
            throw new InvalidArgumentException('Game is not available in the discount matrix: '.$game->value);
        }

        $resolvedMode = $this->resolveMode($game, $mode, $rule);
        $multiplier = $rule->multiplier($resolvedMode);

        // Grade entitlement: resolved server-side from the user's real
        // tier, or from the explicitly supplied anonymous/base tier.
        $tier = $user !== null
            ? $this->tierForUser($user, $asOf)
            : $this->catalog->baseTier();

        $entitlement = $this->entitlements->entitlementFor($tier, $game);

        // Section G formulas, combined through the explicit policy.
        $policyResult = $this->policy->resolve($rule, $entitlement, $base);

        $projectedPayout = $this->roundHalfUp(bcmul($base, $multiplier, 6), self::SCALE);

        return [
            'lottery' => $rule->lottery->value,
            'game' => $game->value,
            'mode' => $resolvedMode,
            'gross_stake' => $base,
            'payout_multiplier' => $multiplier,
            'projected_payout' => $projectedPayout,
            'applicable_discount_percent' => $this->combinedPercent($policyResult, $base),
            'game_discount_percent' => $policyResult['game_discount_percent'],
            'grade_discount_percent' => $policyResult['grade_discount_percent'],
            'discount_amount' => $policyResult['total_discount'],
            'net_stake' => $policyResult['net_stake'],
            'eligibility_state' => $entitlement->state,
            'grade_level' => $tier->level->value,
            'grade_state' => $policyResult['grade_state'],
            'policy_mode' => $policyResult['mode'],
            'policy_note' => $policyResult['policy_note'] ?? $policyResult['note'],
            'rule_version' => $rule->ruleVersion,
            'grade_rule_version' => $tier->ruleVersion,
            'currency' => $rule->currency,
        ];
    }

    /**
     * An anonymous quote: the game rule with NO grade layer at all
     * (what the public matrix publishes, verbatim).
     *
     * @return array<string, mixed>
     */
    public function calculateAnonymous(DiscountGame $game, string $grossStake, ?string $mode = null, ?\DateTimeInterface $asOf = null): array
    {
        return $this->calculate($game, $grossStake, $mode, null, $asOf);
    }

    /**
     * Normalize and validate a stake: plain decimal, non-negative, at
     * most two fractional digits, bounded length. The same shape
     * StrictMoneyAmount enforces at the HTTP boundary.
     */
    private function normalizeStake(string $grossStake): string
    {
        if (preg_match('/^\d{1,13}(\.\d{1,2})?$/', $grossStake) !== 1) {
            throw new InvalidArgumentException(
                'Gross stake must be a plain decimal string with at most two decimals.'
            );
        }

        return bcadd($grossStake, '0', self::SCALE);
    }

    /**
     * Resolve the quote direction. The seven headline games MUST be
     * quoted in an explicit D or R direction; the single-multiplier
     * variants are quoted in their one '*' mode and refuse D/R.
     */
    private function resolveMode(DiscountGame $game, ?string $mode, DiscountRule $rule): string
    {
        if ($game->hasDrawRegularModes()) {
            if ($mode !== DiscountRule::MODE_DIRECT && $mode !== DiscountRule::MODE_REVERSE) {
                throw new InvalidArgumentException(
                    'Headline game '.$game->value.' requires an explicit D or R mode.'
                );
            }

            return $mode;
        }

        if ($mode !== null && $mode !== DiscountRule::MODE_SINGLE) {
            throw new InvalidArgumentException(
                'Game '.$game->value.' is a single-multiplier variant; mode D/R does not exist for it.'
            );
        }

        return DiscountRule::MODE_SINGLE;
    }

    /**
     * The caller's real tier, resolved through the authoritative grade
     * service (the same spend source the evaluator uses).
     */
    private function tierForUser(User $user, ?\DateTimeInterface $asOf): GradeTier
    {
        $grade = app(\App\Services\Account\AccountGradeService::class)->current($user, true);

        return $this->catalog->tierByKey((string) ($grade['grade_key'] ?? 'bronze'))
            ?? $this->catalog->baseTier();
    }

    /**
     * The combined percentage, DERIVED FROM THE MONEY: total discount
     * over gross stake, never a sum of the two rate percentages.
     *
     * @param  array<string, mixed>  $policyResult
     */
    private function combinedPercent(array $policyResult, string $base): string
    {
        $total = bcadd((string) $policyResult['total_discount'], '0', self::SCALE);
        $gross = bcadd($base, '0', self::SCALE);

        if (bccomp($gross, '0', self::SCALE) <= 0) {
            return '0.00';
        }

        return bcadd(bcmul(bcdiv($total, $gross, 6), '100', self::SCALE), '0', self::SCALE);
    }

    /**
     * Half-up rounding at the target scale, on decimal strings.
     */
    private function roundHalfUp(string $value, int $scale): string
    {
        $truncated = bcadd($value, '0', $scale);

        $remainder = bcsub($value, $truncated, $scale + 4);

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
