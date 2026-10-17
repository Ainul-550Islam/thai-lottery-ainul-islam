<?php

declare(strict_types=1);

namespace App\Services\Bot\Signals;

use App\Services\Bot\Contracts\BotSignal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

/**
 * Class DeviceReuseSignal
 *
 * Checks if the same device token is linked across multiple distinct account logins in rapid succession.
 */
class DeviceReuseSignal implements BotSignal
{
    private const MAX_ACCOUNTS_PER_DEVICE = 3;

    public function getKey(): string
    {
        return 'device_reuse';
    }

    public function getWeight(): int
    {
        return 20;
    }

    public function evaluate(Request $request, array $context = []): array
    {
        $deviceToken = $context['device_token'] ?? $request->header('X-Device-Token');

        if (!$deviceToken) {
            return [
                'score' => 0.00,
                'reason_code' => null,
                'metadata' => [],
            ];
        }

        $cacheKey = "bot:device_accounts:{$deviceToken}";

        try {
            $accountCount = (int) Redis::scard($cacheKey);

            if ($accountCount > self::MAX_ACCOUNTS_PER_DEVICE) {
                return [
                    'score' => 0.80,
                    'reason_code' => 'EXCESSIVE_DEVICE_ACCOUNT_SHARING',
                    'metadata' => ['distinct_accounts' => $accountCount],
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
