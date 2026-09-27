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
 * runs no query. It reads config/account_grades.php and returns the same
 * answer to everybody, so there is no request in which it could return
 * somebody's spend, grade or documents.
 *
 * IT IS NOT A SECOND SOURCE OF TRUTH. The tiers come from the same
 * config/account_grades.php that AccountGradeService resolves a real user
 * against, so the advertised ladder and the applied ladder cannot drift.
 * Rates are formatted here for display only; no percentage is recomputed.
 *
 * MONEY AND RATES STAY STRINGS. min_spend and discount_rate are decimal
 * strings in config and are formatted with BCMath-free string operations.
 * Nothing here casts one to a float, because a displayed rate that disagrees
 * with the applied rate by a rounding step is a promise the platform then
 * breaks.
 */
class PublicAccountInfoService
{
    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * The public grade ladder, lowest threshold first.
     *
     * @return array{
     *     status: string,
     *     currency: string,
     *     rule_version: string,
     *     max_discount_display: string|null,
     *     tiers: list<array{key: string, name: string, min_spend: string, discount_display: string, scope: string}>
     * }
     */
    public function gradeLadder(): array
    {
        $tiers = $this->config->get('account_grades.tiers');
        $tiers = is_array($tiers) ? $tiers : [];

        $rows = [];

        foreach ($tiers as $tier) {
            if (! is_array($tier)) {
                continue;
            }

            // A disabled tier is not offered, so it is not advertised.
            if (($tier['enabled'] ?? true) !== true) {
                continue;
            }

            $key = $tier['key'] ?? null;
            $name = $tier['name'] ?? null;

            if (! is_string($key) || ! is_string($name)) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'name' => $name,
                'min_spend' => (string) ($tier['min_spend'] ?? '0.00'),
                'discount_display' => $this->asPercent((string) ($tier['discount_rate'] ?? '0.0000')),
                'scope' => (string) ($tier['discount_scope'] ?? 'operator_markets'),
            ];
        }

        // Sorted by threshold as a STRING comparison would be wrong for
        // '5000.00' vs '25000.00', so compare by length first then value -
        // both are non-negative decimals with two places.
        usort($rows, function (array $a, array $b): int {
            $left = $a['min_spend'];
            $right = $b['min_spend'];

            return [strlen($left), $left] <=> [strlen($right), $right];
        });

        $max = $this->config->get('account_grades.max_discount_rate');

        return [
            'status' => $rows === [] ? 'NOT_CONFIGURED' : 'CONFIGURED',
            'currency' => (string) $this->config->get('account_grades.currency', 'THB'),
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
