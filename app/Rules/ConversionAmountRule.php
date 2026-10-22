<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\Currency;
use App\ValueObjects\ConversionLimits;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a conversion amount against the platform's published envelope.
 *
 * WHY THIS RULE DELEGATES INSTEAD OF COMPARING NUMBERS
 * The minimum, the maximum and the scale all live in config/conversions.php and
 * are already read by ConversionLimitService. A rule that re-implemented the
 * comparison would be a second source of truth for the same policy, and the two
 * would drift the first time an operator changed a limit: the form would accept
 * an amount the engine then refused, or reject one it would have accepted.
 * Everything here is read from the value object the engine itself uses.
 *
 * WHY IT IS NOT ONLY A VALIDATION RULE
 * A validation message is the fastest way to tell a member what is wrong, but it
 * is not the mechanism that keeps the money safe — the service refuses an
 * out-of-envelope amount under the wallet lock regardless of what any request
 * validated. This rule exists so the member hears about it before a policy
 * decision is built, and so the answer is a field error on the form rather than
 * a page-level refusal.
 */
class ConversionAmountRule implements ValidationRule
{
    public function __construct(
        private readonly Currency $currency = Currency::THB,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            $fail('The :attribute must be an amount of money.');

            return;
        }

        $amount = trim((string) $value);

        // A non-numeric string is rejected here rather than being cast: PHP's
        // (float) conversion accepts "1e5", " 12" and "12abc", each of which
        // becomes a different number than the member typed on some clients.
        if (preg_match('/^\d+(\.\d+)?$/', $amount) !== 1) {
            $fail('The :attribute must be a positive number with at most two decimal places, for example 100.00.');

            return;
        }

        if (str_contains($amount, '.') && strlen(substr($amount, strpos($amount, '.') + 1)) > 2) {
            $fail('The :attribute cannot carry more than two decimal places; the smallest unit is 0.01.');

            return;
        }

        $limits = ConversionLimits::fromConfig((array) config('conversions.limits', []), $this->currency);

        if (bccomp($amount, $limits->minimum(), 2) === -1) {
            $fail(sprintf(
                'The :attribute must be at least %s %s.',
                $limits->minimum(),
                $this->currency->value,
            ));

            return;
        }

        $maximum = $limits->maximum();

        if ($maximum !== null && bccomp($amount, $maximum, 2) === 1) {
            $fail(sprintf(
                'The :attribute cannot exceed %s %s in a single conversion.',
                $maximum,
                $this->currency->value,
            ));

            return;
        }

        if (bccomp($amount, '0', 2) !== 1) {
            $fail('The :attribute must be greater than zero.');
        }
    }

    /**
     * A rule that can be serialised survives a queued form request and a cached
     * validation rule set; keeping the currency as an enum rather than a
     * container-resolved object is what makes that possible.
     */
    public function __toString(): string
    {
        return 'conversion_amount:'.$this->currency->value;
    }
}
