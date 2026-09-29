<?php

declare(strict_types=1);

namespace App\Services\Account;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The publishable parts of the account programme: the grade ladder and what
 * verification asks for.
 *
 * WHY A PUBLIC SERVICE AT ALL. /account/grade and /account/verification are
 * a signed-in user's own pages and stay that way. But the LADDER itself -
 * which grades exist, what each costs to reach, what discount each carries -
 * is marketing information, and someone deciding whether to register cannot
 * see it from behind a login. A page-by-page comparison against the
 * reference surface found exactly that gap.
 *
 * IT CANNOT LEAK A PERSON. This class takes no user, touches no model and
 * runs no query. It reads the canonical authorities and returns the same
 * answer to everybody, so there is no request in which it could return
 * somebody's spend, grade or documents.
 *
 * IT IS NOT A SECOND SOURCE OF TRUTH. GRADE PARITY BATCH: the ladder is
 * projected through GradeTierCatalog + GradeDiscountEntitlementService -
 * the same authorities the evaluator resolves real users against - so the
 * advertised ladder, its per-tier game entitlements and the applied ladder
 * cannot drift. Rates are formatted here for display only; no percentage is
 * recomputed.
 *
 * MONEY AND RATES STAY STRINGS. min_spend and discount_rate are decimal
 * strings in config and are formatted with BCMath-free string operations.
 * Nothing here casts one to a float, because a displayed rate that disagrees
 * with the applied rate by a rounding step is a promise the platform then
 * breaks.
 */
class PublicAccountInfoService
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly GradeTierCatalog $tiers,
        private readonly GradeDiscountEntitlementService $entitlements,
    ) {}

    /**
     * The public grade ladder, SL 1..5 first.
     *
     * GRADE PARITY BATCH: the ladder is now projected through
     * GradeTierCatalog + GradeDiscountEntitlementService — the same
     * authorities the evaluator resolves real users against — so the
     * advertised ladder, its per-tier game entitlements and the applied
     * ladder can never drift. The row shape keeps the original fields
     * (key/name/min_spend/discount_display/scope) and adds the canonical
     * columns (sl/icon/eligible_games/rule_version/effective dates).
     *
     * @return array{
     *     status: string,
     *     currency: string,
     *     period_days: int,
     *     rule_version: string,
     *     max_discount_display: string|null,
     *     tiers: list<array<string, mixed>>
     * }
     */
    public function gradeLadder(): array
    {
        $tiers = $this->tiers->publicTiers();

        $rows = [];

        foreach ($tiers as $tier) {
            $games = $this->entitlements->eligibleGames($tier);

            $rows[] = [
                'key' => $tier->key,
                'name' => $tier->name,
                'sl' => $tier->sl,
                'min_spend' => $tier->minSpend,
                'discount_display' => $this->asPercent($tier->discountRate),
                'scope' => $tier->discountScope,
                'icon' => $tier->icon,
                'eligible_games' => array_map(
                    static fn (\App\Enums\DiscountGame $game): array => [
                        'key' => $game->value,
                        'label' => self::gameLabel($game),
                        'lottery' => $game->lottery()->value,
                    ],
                    $games,
                ),
                'eligible_game_count' => count($games),
                'effective_from' => $tier->effectiveFrom,
                'effective_to' => $tier->effectiveTo,
                'rule_version' => $tier->ruleVersion,
            ];
        }

        $max = $this->config->get('account_grades.max_discount_rate');

        return [
            'status' => $rows === [] ? 'NOT_CONFIGURED' : 'CONFIGURED',
            'currency' => (string) $this->config->get('account_grades.currency', 'THB'),
            'period_days' => max(1, (int) $this->config->get('account_grades.grade_period_days', 30)),
            'rule_version' => (string) $this->config->get('account_grades.rule_version', '1'),
            'max_discount_display' => is_string($max) && $max !== '' ? $this->asPercent($max) : null,
            'tiers' => $rows,
        ];
    }

    /**
     * What the verification review looks at, as a list of steps.
     *
     * DELIBERATELY GENERIC. It names the CATEGORIES of document a review
     * needs, never an example document, an example number or a sample image.
     * A public page that shows what an accepted ID looks like is a template
     * for forging one.
     *
     * @return array{status: string, steps: list<array{key: string, order: int}>}
     */
    public function verificationSteps(): array
    {
        // Step keys only; the wording lives in the translation files so both
        // locales stay in step and no sentence is hardcoded here.
        $keys = ['register', 'submit_request', 'provide_documents', 'review', 'outcome'];

        $steps = [];

        foreach ($keys as $index => $key) {
            $steps[] = ['key' => $key, 'order' => $index + 1];
        }

        return ['status' => 'CONFIGURED', 'steps' => $steps];
    }

    /**
     * Human-readable label for a matrix game, resolved from the
     * translation layer with a stable non-translated fallback.
     */
    private static function gameLabel(\App\Enums\DiscountGame $game): string
    {
        $label = trans($game->labelKey());

        return is_string($label) && $label !== $game->labelKey()
            ? $label
            : ucfirst(str_replace('_', ' ', $game->value));
    }

    /**
     * '0.0150' -> '1.50%'
     *
     * String arithmetic only: the decimal point is moved two places by
     * padding and slicing, never by multiplying a float by 100, which turns
     * 0.0150 into 1.4999999999999999 on the way to a rendered page.
     */
    private function asPercent(string $rate): string
    {
        $negative = str_starts_with($rate, '-');
        $rate = ltrim($rate, '-');

        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');

        $digits = $whole.$fraction;
        $pointAt = strlen($whole) + 2;

        $digits = str_pad($digits, $pointAt + 2, '0');

        $shiftedWhole = ltrim(substr($digits, 0, $pointAt), '0');
        $shiftedFraction = substr($digits, $pointAt, 2);

        $shiftedWhole = $shiftedWhole === '' ? '0' : $shiftedWhole;

        return ($negative ? '-' : '').$shiftedWhole.'.'.$shiftedFraction.'%';
    }
}
