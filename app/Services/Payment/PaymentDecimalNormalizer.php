<?php

declare(strict_types=1);

namespace App\Services\Payment;

use InvalidArgumentException;

/**
 * Exact BCMath decimal normalizer for inbound payment & webhook amounts.
 * Eliminates IEEE-754 `(float)` precision loss and rejects scientific notation,
 * NaN/INF, negative numbers, or excess fractional digits.
 */
final class PaymentDecimalNormalizer
{
    public static function normalizeNonNegative(int|string $raw, int $scale = 2, string $field = 'amount'): string
    {
        if ($scale < 0 || $scale > 8) {
            throw new InvalidArgumentException("Unsupported decimal scale: {$scale}");
        }

        $str = trim((string) $raw);
        if ($str === '') {
            throw new InvalidArgumentException("Payment field [{$field}] cannot be empty.");
        }

        $pattern = $scale === 0
            ? '/^\d{1,16}$/'
            : sprintf('/^\d{1,16}(?:\.\d{1,%d})?$/', $scale);

        if (preg_match($pattern, $str) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Payment field [%s] must be an exact non-negative decimal with at most %d decimal places.',
                $field,
                $scale,
            ));
        }

        $zero = $scale === 0 ? '0' : '0.'.str_repeat('0', $scale);

        return bcadd($str, $zero, $scale);
    }

    public static function normalizePositive(int|string $raw, int $scale = 2, string $field = 'amount'): string
    {
        $normalized = self::normalizeNonNegative($raw, $scale, $field);
        $zero = $scale === 0 ? '0' : '0.'.str_repeat('0', $scale);

        if (bccomp($normalized, $zero, $scale) <= 0) {
            throw new InvalidArgumentException("Payment field [{$field}] must be strictly positive.");
        }

        return $normalized;
    }

    /**
     * Normalize an inbound provider-signed JSON webhook amount where json_decode()
     * may materialize exact JSON decimals (e.g. 500.00) as PHP float, while still
     * rejecting non-finite values, scientific notation, inexact binary floats, and
     * excess fractional precision before BCMath normalization.
     */
    public static function normalizeInboundWebhookPositive(mixed $raw, int $scale = 4, string $field = 'amount'): string
    {
        if (is_int($raw) || is_string($raw)) {
            return self::normalizePositive($raw, $scale, $field);
        }

        if (is_float($raw)) {
            if (! is_finite($raw) || $raw <= 0.0) {
                throw new InvalidArgumentException("Payment field [{$field}] must be a finite positive decimal.");
            }

            $encoded = json_encode($raw, JSON_PRESERVE_ZERO_FRACTION);
            if (! is_string($encoded) || str_contains($encoded, 'e') || str_contains($encoded, 'E')) {
                throw new InvalidArgumentException("Payment field [{$field}] cannot use scientific notation.");
            }

            return self::normalizePositive($encoded, $scale, $field);
        }

        throw new InvalidArgumentException("Payment field [{$field}] has an invalid type.");
    }
}
