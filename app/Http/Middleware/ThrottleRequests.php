<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests as BaseThrottleRequests;

/**
 * Not applied to any route in the running route table.
 *
 * named limiters work through the framework middleware, because the throttle alias in
 * bootstrap/app.php resolves to Illuminate\Routing\Middleware\ThrottleRequests and this
 * subclass adds nothing to it. The registrations that actually create the ceilings are the
 * RateLimiter::for() calls in AppServiceProvider, attached per route in routes/*.php.
 * So this file is not "named limiter integration"; it is the place to put app-wide
 * throttling behaviour if a route ever needs it, and the reachability test fails if it
 * grows behaviour while still being unreachable.
 */
class ThrottleRequests extends BaseThrottleRequests {}
