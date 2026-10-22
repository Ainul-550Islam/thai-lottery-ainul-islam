<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use App\Enums\Currency;

/** Provider parsing must preserve absence and reject precision loss, never guess a country. */
final class WebhookMoneyFacts
{
    public static function currency(mixed $value): ?Currency
    {
        return is_string($value) ? Currency::tryFrom(strtoupper($value)) : null;
    }

    public static function decimal(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = (string) $value;

        return preg_match('/^(?:0|[1-9]\d{0,17})(?:\.\d{1,2})?$/D', $value) === 1 ? $value : null;
    }

    /** Stripe's supported currencies here have two minor-unit digits. */
    public static function minorUnits(mixed $value): ?string
    {
        if ((! is_string($value) && ! is_int($value))
            || preg_match('/^(?:0|[1-9]\d{0,19})$/D', (string) $value) !== 1) {
            return null;
        }

        return self::decimal(bcdiv((string) $value, '100', 2));
    }
}
