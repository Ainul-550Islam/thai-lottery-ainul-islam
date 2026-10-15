<?php

declare(strict_types=1);

namespace App\Services\Velocity;

use InvalidArgumentException;

/** Pure bounded sliding-window arithmetic, reusable by future velocity rules. */
final class VelocityWindow
{
    /**
     * Keep the newest $capacity hits in (now - seconds, now], then append this hit.
     * Caller must serialize read/append/write using a shared lock.
     *
     * @param  list<int>  $timestamps
     * @return list<int>
     */
    public function append(array $timestamps, int $now, int $seconds, int $capacity): array
    {
        if ($seconds < 1 || $seconds > 86400 || $capacity < 1 || $capacity > 10001 || ! array_is_list($timestamps) || count($timestamps) > 10001) {
            throw new InvalidArgumentException('Invalid velocity window input.');
        }
        $active = [];
        foreach ($timestamps as $timestamp) {
            if (! is_int($timestamp) || $timestamp > $now) {
                throw new InvalidArgumentException('Invalid or future velocity timestamp.');
            }
            if ($timestamp > $now - $seconds) {
                $active[] = $timestamp;
            }
        }
        $active[] = $now;
        sort($active, SORT_NUMERIC);

        return array_slice($active, -$capacity);
    }
}
