<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A published discount percentage (PROMPT 2, section F).
 *
 * Accepts ONLY a whole-or-decimal percentage string between '0.00' and
 * '100.00' with at most 4 fraction digits. The bound check uses
 * bccomp at scale 4 - never a float comparison, because a percentage
 * like '99.9999' compared as a float can pass a <= 100 test on one
 * platform and fail on another.
 *
 * Floats are rejected OUTRIGHT: a percentage that arrives as a float
 * has already lost the guarantee that the stored value, the applied
 * value and the displayed value are the same number.
 *
 * DO NOT point this rule at account_grades.tiers.*.discount_rate. That field is a
 * FRACTION on 0..1 ('0.0200' is two per cent), validated at load time by
 * GradeTierCatalog::loadTiers(), which refuses anything above '1.0000'. This rule
 * bounds 0..100 for a published percentage, so wiring it to the fraction field would
 * widen the accepted range a hundred-fold - a tier could load '15.0000' as a valid
 * rate. The percent bounds that this rule mirrors are already enforced live, against
 * the right field, in LottoDiscountService (discounts.limits.min_percentage /
 * max_percentage). Nothing here is the missing half of that control; if a published
 * percentage endpoint is ever built, this is the rule for it.
 */
final readonly class ValidDiscountPercentage implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a decimal percentage string.');

            return;
        }

        if (preg_match('/^\d{1,3}(\.\d{1,4})?$/', $value) !== 1) {
            $fail('The :attribute must be a plain decimal percentage.');

            return;
        }

        if (bccomp($value, '0.0000', 4) < 0 || bccomp($value, '100.0000', 4) > 0) {
            $fail('The :attribute must be between 0.00 and 100.00.');
        }
    }
}
