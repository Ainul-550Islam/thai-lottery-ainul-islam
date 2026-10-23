<?php

declare(strict_types=1);

namespace App\Services\Bot\Signals;

use App\Services\Bot\Contracts\BotSignal;
use App\Services\Bot\DeviceFingerprintService;
use App\Services\Bot\DTOs\SignalResult;
use App\Services\Velocity\VelocityWindow;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/** Composite subnet/UA/language rate evidence across paths, not an IP-only firewall. */
final class RequestRateSignal implements BotSignal
{
    public function __construct(
        private readonly Repository $config,
        private readonly CacheRepository $cache,
        private readonly DeviceFingerprintService $fingerprints,
        private readonly VelocityWindow $window,
    ) {}

    public function score(Request $request): SignalResult
    {
        $seconds = $this->config->get('bot.request_rate.window_seconds', 60);
        $maximum = $this->config->get('bot.request_rate.max_requests', 60);
        if (! is_int($seconds) || $seconds < 1 || $seconds > 86400 || ! is_int($maximum) || $maximum < 1 || $maximum > 10000) {
            throw new InvalidArgumentException('Invalid bot request-rate configuration.');
        }
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            throw new \RuntimeException('Bot rate evidence requires a lock-capable cache store.');
        }
        $key = 'bot:rate:v1:'.$this->fingerprints->fingerprint($request);
        // Use a shared Redis/database store in production, not process-local ArrayStore.
        // Lock lease must exceed the cache round-trip timeout; multi-worker proof is pending.
        $result = $store->lock($key.':lock', 10)->get(function () use ($key, $seconds, $maximum): SignalResult {
            $previous = $this->cache->get($key, []);
            if (! is_array($previous)) {
                throw new \RuntimeException('Bot rate cache state is corrupt.');
            }
            $timestamps = $this->window->append($previous, Carbon::now()->getTimestamp(), $seconds, $maximum + 1);
            if (! $this->cache->put($key, $timestamps, $seconds)) {
                throw new \RuntimeException('Bot rate cache could not persist evidence.');
            }
            $breached = count($timestamps) > $maximum;

            return new SignalResult('request_rate', $breached ? 100 : 0, $breached ? ['REQUEST_RATE_EXCEEDED'] : []);
        });
        if (! $result instanceof SignalResult) {
            throw new \RuntimeException('Bot rate evidence lock is busy.');
        }

        return $result;
    }
}
