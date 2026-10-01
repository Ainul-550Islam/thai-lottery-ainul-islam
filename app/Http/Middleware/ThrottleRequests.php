<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests as BaseThrottleRequests;

/**
 * Rate Limiting Middleware with Named Limiter Integration.
 */
class ThrottleRequests extends BaseThrottleRequests
{
}
