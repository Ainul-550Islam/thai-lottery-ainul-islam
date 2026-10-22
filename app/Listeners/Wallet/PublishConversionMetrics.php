<?php

declare(strict_types=1);

namespace App\Listeners\Wallet;

use App\Enums\WalletConversionRejectionReason;
use App\Events\Wallet\ConversionCompleted;
use App\Events\Wallet\ConversionRejected;
use App\Events\Wallet\ConversionRequested;
use App\Events\Wallet\ConversionReversed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Maintains the counters that answer "is this feature working right now?".
 *
 * WHY COUNTERS AND NOT A METRICS LIBRARY
 * This platform has no application-metrics backend (the gap report records that
 * explicitly), and bolting one on inside a feature branch would be a
 * half-migration that leaves nobody able to read the numbers. What it does have
 * is a cache and a log. So counters are kept in the cache with a rolling window
 * and mirrored to the log as structured lines — the log line is the durable
 * record an operator can grep, and the cache counter is what the alerting
 * listener and the admin dashboard read cheaply.
 *
 * WHAT IS COUNTED, AND WHY THESE FOUR THINGS
 *   requested  — demand: how many members tried.
 *   completed  — supply: how many succeeded.
 *   rejected   — friction, split BY CODE, because a single "failures" number
 *                cannot distinguish a member with no balance from a broken
 *                deployment.
 *   reversed   — operator intervention. A reversal is not a failure, but a
 *                sudden run of them means something upstream went wrong.
 *
 * COUNTERS ARE BEST-EFFORT
 * A cache that is unavailable must not fail a conversion, so every operation
 * here is wrapped: the counters are operational telemetry, and the durable
 * record of the movement already exists in the database and the log.
 */
class PublishConversionMetrics
{
    private const WINDOW_SECONDS = 900;

    private const PREFIX = 'conversion:metrics:';

    public function handle(ConversionRequested|ConversionCompleted|ConversionRejected|ConversionReversed $event): void
    {
        try {
            $key = match (true) {
                $event instanceof ConversionRequested => 'requested',
                $event instanceof ConversionCompleted => 'completed',
                $event instanceof ConversionReversed => 'reversed',
                default => 'rejected:'.$event->errorCode,
            };

            $this->increment($key);

            Log::info('conversion.metric', [
                'metric' => $key,
                'direction' => $event->direction->value,
                'channel' => $event->channel->value,
                'user_id' => $event->userId,
                'window_seconds' => self::WINDOW_SECONDS,
                'count' => $this->count($key),
            ]);
        } catch (\Throwable) {
            // Telemetry must never be the reason a conversion fails.
        }
    }

    private function increment(string $key): void
    {
        $cacheKey = self::PREFIX.$key;

        if (! Cache::has($cacheKey)) {
            // First observation in this window: seed the key with a TTL so the
            // counter decays on its own rather than needing a sweeper.
            Cache::put($cacheKey, 1, self::WINDOW_SECONDS);

            return;
        }

        Cache::increment($cacheKey);
    }

    private function count(string $key): int
    {
        return (int) (Cache::get(self::PREFIX.$key) ?? 0);
    }

    /**
     * The refusal counts for the current window, keyed by refusal code.
     *
     * @return array<string, int>
     */
    public function refusalCounts(): array
    {
        // Cache stores do not support enumerating keys portably across every
        // driver this platform can be configured with, so the set of codes to
        // read is derived from the enum rather than by listing cache keys.
        $counts = [];

        foreach (WalletConversionRejectionReason::cases() as $reason) {
            $value = $this->count('rejected:'.$reason->value);

            if ($value > 0) {
                $counts[$reason->value] = $value;
            }
        }

        return $counts;
    }
}
