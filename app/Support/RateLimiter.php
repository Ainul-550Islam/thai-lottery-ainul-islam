<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Cache\RateLimiter as FrameworkRateLimiter;

/**
 * Compatibility adapter for the pre-Laravel-12 named-limiter probe.
 * The framework's limiter() method remains authoritative.
 */
final class RateLimiter extends FrameworkRateLimiter
{
    public function hasNamedLocker(string $name): bool
    {
        return $this->limiter($name) !== null;
    }
}
