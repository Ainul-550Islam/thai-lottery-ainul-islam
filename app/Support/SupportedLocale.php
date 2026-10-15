<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for the public UI locales.
 *
 * Every place that accepts a locale from the request (the SetLocale
 * middleware, the /locale/{locale} switch route) must validate through this
 * class, so adding a language happens in exactly one place.
 *
 * Only strict, exact, lower-case codes are accepted. Anything else returns
 * null and the caller keeps its current locale.
 */
final class SupportedLocale
{
    public const EN = 'en';

    public const TH = 'th';

    public const BN = 'bn';

    /** @var list<string> */
    public const ALL = [self::EN, self::TH, self::BN];

    public static function isSupported(mixed $candidate): bool
    {
        return is_string($candidate) && in_array($candidate, self::ALL, true);
    }

    /**
     * Returns the supported locale code, or null when the value is not an
     * allow-listed code (never a partial or fuzzy match).
     */
    public static function normalise(mixed $candidate): ?string
    {
        return self::isSupported($candidate) ? $candidate : null;
    }
}
