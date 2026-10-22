<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class SlidingWindowRateLimiterMiddleware
 *
 * Sub-millisecond sliding-window rate limiter powered by Redis Lua scripts,
 * protecting against high-frequency bot flooding and DDoS bursts.
 */
class SlidingWindowRateLimiterMiddleware
{
    private const WINDOW_SIZE_SECONDS = 10;

    private const MAX_REQUESTS_PER_WINDOW = 50;

    public function handle(Request $request, Closure $next): Response
    {
        $identifier = $request->user()?->id ?? $request->ip();
        $rateLimitKey = "ratelimit:sliding:{$identifier}:".substr(md5($request->path()), 0, 8);

        $now = microtime(true);
        $windowStart = $now - self::WINDOW_SIZE_SECONDS;

        // Lua Script: Sliding Window Log in Redis Sorted Set
        $luaScript = <<<'LUA'
-- Remove timestamps older than window start
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])

-- Count current requests in window
local current_requests = redis.call('ZCARD', KEYS[1])

if current_requests < tonumber(ARGV[2]) then
    -- Add current request timestamp
    redis.call('ZADD', KEYS[1], ARGV[3], ARGV[4])
    redis.call('EXPIRE', KEYS[1], ARGV[5])
    return {1, tonumber(ARGV[2]) - current_requests - 1}
else
    return {0, 0}
end
LUA;

        $memberId = $now.':'.random_int(1000, 9999);
        $result = Redis::eval(
            $luaScript,
            1,
            $rateLimitKey,
            (string) $windowStart,
            (string) self::MAX_REQUESTS_PER_WINDOW,
            (string) $now,
            $memberId,
            (string) (self::WINDOW_SIZE_SECONDS * 2)
        );

        $isAllowed = (int) $result[0] === 1;
        $remaining = (int) $result[1];

        if (! $isAllowed) {
            return response()->json([
                'success' => false,
                'error' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Too many requests. Please throttle your client rate.',
            ], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) self::WINDOW_SIZE_SECONDS,
                'X-RateLimit-Limit' => (string) self::MAX_REQUESTS_PER_WINDOW,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) self::MAX_REQUESTS_PER_WINDOW);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $remaining));

        return $response;
    }
}
