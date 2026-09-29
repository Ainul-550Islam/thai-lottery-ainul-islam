<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\DTOs\Account\GradeTier;
use App\DTOs\Lottery\DiscountRule;
use App\Enums\DiscountGame;
use App\Enums\DiscountLottery;
use App\Services\Account\GradeDiscountEntitlementService;
use App\Services\Account\GradeTierCatalog;

/**
 * The whitelisted PUBLIC projections of the grade + matrix surfaces
 * (PROMPT 2, section I).
 *
 * WHAT LEAVES HERE
 *   Account grade page: SL, tier name, threshold, discount percent,
 *   icon, the ordered entitled-game list, rule version.
 *   Discount page: lottery, game, D multiplier, R multiplier, discount
 *   percent or NOT_CONFIGURED, human-readable game name.
 *
 * WHAT MAY NEVER LEAVE
 *   internal database ids, any user's spend figures, private commission
 *   rules, gateway credentials, internal margins, transaction ids,
 *   fraud/internal scoring, security metadata. The projections are
 *   field-by-field whitelists — nothing rides along implicitly.
 *
 * Both projections read the same canonical authorities the calculations
 * use (GradeTierCatalog, CanonicalDiscountMatrixService), so the pages
 * can never disagree with the engine.
 */
final class DiscountParityProjectionService
{
    public function __construct(
        private readonly GradeTierCatalog $catalog,
        private readonly CanonicalDiscountMatrixService $matrix,
        private readonly GradeDiscountEntitlementService $entitlements,
    ) {}

    /**
     * ACCOUNT GRADE public projection: the five programme tiers with
     * their entitlement lists, ordered SL 1..5.
     *
     * @return array<string, mixed>
     */
    public function gradeTiers(?string $locale = null): array
    {
        $locale = $locale ?? (string) app()->getLocale();

        $tiers = [];

        foreach ($this->catalog->publicTiers() as $tier) {
            $games = [];

            foreach ($this->entitlements->eligibleGames($tier) as $game) {
                $games[] = [
                    'key' => $game->value,
                    'label' => self::gameLabel($game, $locale),
                    'lottery' => $game->lottery()->value,
                ];
            }

            $tiers[] = [
                'key' => $tier->key,
                'name' => $tier->name,
                'sl' => $tier->sl,
                'min_spend' => $tier->minSpend,
                'discount_percent' => $tier->discountPercent(),
                'icon' => $tier->icon,
                'eligible_games' => $games,
                'eligible_game_count' => count($games),
                'rule_version' => $tier->ruleVersion,
            ];
        }

        return [
            'status' => $tiers === [] ? 'NOT_CONFIGURED' : 'CONFIGURED',
            'currency' => (string) config('account_grades.currency', 'THB'),
            'rule_version' => (string) config('account_grades.rule_version', '1'),
            'tiers' => $tiers,
        ];
    }

    /**
     * DISCOUNT PAGE public projection: both lottery families with their
     * game rules (enabled + publicly visible only) and the published
     * affiliate commission lines.
     *
     * @return array<string, mixed>
     */
    public function discountMatrix(?string $locale = null): array
    {
        $locale = $locale ?? (string) app()->getLocale();
        $matrix = $this->matrix->matrix();

        $lotteries = [];
        foreach (DiscountLottery::cases() as $family) {
            $rules = [];

            foreach ($matrix->rules($family) as $rule) {
                if (! $rule->enabled || ! $rule->publicVisible) {
                    continue;
                }

                $rules[] = $this->projectRule($rule, $locale);
            }

            $lotteries[] = [
                'key' => $family->value,
                'label' => self::lotteryLabel($family, $locale),
                'affiliate_commission_percent' => (string) ($matrix->affiliateCommission[$family->value] ?? '0.00'),
                'games' => $rules,
            ];
        }

        return [
            'status' => $lotteries === [] ? 'NOT_CONFIGURED' : 'CONFIGURED',
            'rule_version' => $matrix->ruleVersion,
            'currency' => $matrix->currency,
            'base_stake' => $matrix->baseStake,
            'lotteries' => $lotteries,
        ];
    }

    /**
     * Per-game entitlement rows for ONE user's own grade, in canonical
     * public-games order. Used by the authenticated grade page and the
     * account grade API: every public matrix game appears exactly once
     * with an explicit eligible / not-eligible answer — silence is not
     * a state the caller is left to guess.
     *
     * Whitelisted fields only: game key, label, lottery family,
     * eligibility state, rate, rule version. No ids, no internals.
     *
     * @return list<array<string, mixed>>
     */
    public function userEntitlements(\App\DTOs\Account\GradeTier $tier, ?string $locale = null): array
    {
        $locale = $locale ?? (string) app()->getLocale();

        $rows = [];

        foreach (DiscountGame::publicGames() as $game) {
            $entitlement = $this->entitlements->entitlementFor($tier, $game);
            $row = $entitlement->toArray();
            $row['label'] = self::gameLabel($game, $locale);
            $row['lottery'] = $game->lottery()->value;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * One game-rule row projection (whitelisted fields only).
     *
     * @return array<string, mixed>
     */
    public function projectRule(DiscountRule $rule, ?string $locale = null): array
    {
        $locale = $locale ?? (string) app()->getLocale();

        return [
            'lottery' => $rule->lottery->value,
            'game' => $rule->game->value,
            'label' => self::gameLabel($rule->game, $locale),
            'd_multiplier' => $rule->modes[DiscountRule::MODE_DIRECT] ?? null,
            'r_multiplier' => $rule->modes[DiscountRule::MODE_REVERSE] ?? null,
            'multiplier' => $rule->modes[DiscountRule::MODE_SINGLE] ?? null,
            'has_modes' => $rule->game->hasDrawRegularModes(),
            'discount' => $rule->discount,
            'discount_state' => $rule->discountState(),
            'discount_display' => $rule->discountDisplay(),
            'base_stake' => $rule->baseStake,
            'currency' => $rule->currency,
            'rule_version' => $rule->ruleVersion,
        ];
    }

    private static function gameLabel(DiscountGame $game, string $locale): string
    {
        $label = trans($game->labelKey(), [], $locale);

        return is_string($label) && $label !== $game->labelKey()
            ? $label
            : ucfirst(str_replace('_', ' ', $game->value));
    }

    private static function lotteryLabel(DiscountLottery $lottery, string $locale): string
    {
        $label = trans($lottery->labelKey(), [], $locale);

        return is_string($label) && $label !== $lottery->labelKey()
            ? $label
            : ucfirst(str_replace('_', ' ', $lottery->value));
    }
}
