<?php

declare(strict_types=1);

namespace App\Services\Bot\Signals;

use App\Services\Bot\Contracts\BotSignal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

/**
 * Class FailedAuthenticationSignal
 *
 * Checks recent repeated authentication failures originating from the same client identifier.
 */
class FailedAuthenticationSignal implements BotSignal
{
    private const FAIL_WINDOW_SECONDS = 900; // 15 mins
    private const FAIL_THRESHOLD = 5;

    public function getKey(): string
    {
        return 'failed_auth';
    }

    public function getWeight(): int
    {
        return 25;
    }

    public function evaluate(Request $request, array $context = []): array
    {
        $identifier = $context['client_ip'] ?? $request->ip();
        $cacheKey = "auth:failures:ip:{$identifier}";

        try {
            $failures = (int) Redis::get($cacheKey);

            if ($failures >= self::FAIL_THRESHOLD) {
                return [
                    'score' => min(1.0, 0.6 + (($failures - self::FAIL_THRESHOLD) * 0.1)),
                    'reason_code' => 'REPEATED_AUTHENTICATION_FAILURES',
                    'metadata' => ['failure_count' => $failures],
                ];
            }
        } catch (\Throwable) {
            // Fail open
        }

        return [
            'score' => 0.00,
            'reason_code' => null,
            'metadata' => [],
        ];
    }
}
