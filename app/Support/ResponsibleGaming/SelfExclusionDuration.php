<?php

declare(strict_types=1);

namespace App\Support\ResponsibleGaming;

use InvalidArgumentException;

/**
 * The self-exclusion horizons the player API accepts, in one place.
 *
 * Two endpoints used to keep their own lists and disagreed (one accepted 90d,
 * the other did not). '24h' is the one horizon shorter than a day and
 * resolves to a single day of exclusion.
 */
final class SelfExclusionDuration
{
    /** @var array<string, int> */
    private const DAYS = [
        '24h' => 1,
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
        '365d' => 365,
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::DAYS);
    }

    public static function days(string $key): int
    {
        return self::DAYS[$key]
            ?? throw new InvalidArgumentException("Unknown self-exclusion duration [{$key}].");
    }
}
