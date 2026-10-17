<?php

declare(strict_types=1);

namespace App\Services\Bot\Signals;

use App\Services\Bot\Contracts\BotSignal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

/**
 * Class RequestVelocitySignal
 *
 * Tracks sliding-window request volume across IP, session, and device tokens.
 */
class RequestVelocitySignal implements BotSignal
{
    private const WINDOW_SECONDS = 5;
    private const HIGH_BURST_THRESHOLD = 20;

    public function getKey(): string
    {
        return 'request_velocity';
    }

    public function getWeight(): int
    {
        return 30;
    }

    public function evaluate(Request $request, array $context = []): array
    {
        $identifier = $context['client_ip'] ?? $request->ip();
        $cacheKey = "bot:velocity:ip:{$identifier}";

        try {
            $count = (int) Redis::incr($cacheKey);
            if ($count === 1) {
                Redis::expire($cacheKey, self::WINDOW_SECONDS);
            }

            if ($count > self::HIGH_BURST_THRESHOLD) {
                return [
                    'score' => min(1.0, 0.5 + (($count - self::HIGH_BURST_THRESHOLD) * 0.05)),
                    'reason_code' => 'BURST_REQUEST_VELOCITY_EXCEEDED',
                    'metadata' => ['burst_count' => $count, 'window_sec' => self::WINDOW_SECONDS],
                ];
            }
        } catch (\Throwable) {
            // Fail open on Redis failure
        }

        return [
            'score' => 0.00,
            'reason_code' => null,
            'metadata' => [],
        ];
    }
}
