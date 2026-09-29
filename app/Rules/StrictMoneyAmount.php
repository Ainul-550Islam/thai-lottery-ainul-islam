<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A strict money amount (PROMPT 2, section G).
 *
 * Accepts ONLY a plain decimal string: 1-13 whole digits, optionally a
 * decimal point followed by exactly 1 or 2 fraction digits
 * (^\d{1,13}(\.\d{1,2})?$). Everything else is refused BEFORE
 * is_numeric() is ever consulted, because PHP's is_numeric() accepts
 * far more than money ever should:
 *   - signs: '-5.00', '+5'
 *   - scientific notation: '1e3', '2.5E-2'
 *   - leading/trailing whitespace and hex strings ('0x1A' in old PHPs)
 *
 * Money that arrives in any of those shapes is a client trying to
 * smuggle a number the decimal engine cannot represent identically.
 * The server is the single source of truth for every monetary value.
 */
final readonly class StrictMoneyAmount implements ValidationRule
{
    public function __construct(
        private int $maxWholeDigits = 13,
        private int $maxFractionDigits = 2,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_float($value) || is_int($value) || is_bool($value) || $value === null) {
            $fail('The :attribute must be a plain decimal string.');

            return;
        }

        if (! is_string($value)) {
            $fail('The :attribute must be a plain decimal string.');

            return;
        }

        // Reject signs, exponents, separators and whitespace before any
        // numeric parsing could launder them into something acceptable.
        if (preg_match('/[\s+\-eE,]/', $value) === 1) {
            $fail('The :attribute must be a plain decimal amount.');

            return;
        }

        $pattern = sprintf('/^\d{1,%d}(\.\d{1,%d})?$/', $this->maxWholeDigits, $this->maxFractionDigits);

        if (preg_match($pattern, $value) !== 1) {
            $fail('The :attribute must be a plain decimal amount.');
        }
    }
}
