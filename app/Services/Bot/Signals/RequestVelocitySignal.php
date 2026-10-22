<?php

declare(strict_types=1);

namespace App\Services\Bot\Signals;

use App\Services\Bot\Contracts\BotSignal;
use App\Services\Bot\DTOs\SignalResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Measures short-window request bursts from one client IP.
 *
 * B1 FIX — THIS CLASS DID NOT IMPLEMENT ITS OWN INTERFACE.
 *
 * BotSignal declares `score(Request $request): SignalResult`. This class
 * provided getKey(), getWeight() and evaluate(Request, array): array — an
 * earlier revision's shape. Being concrete and non-conforming, PHP rejected it
 * at class load with a fatal error, not an exception. It stayed latent only
 * because nothing loaded it (see the note in FailedAuthenticationSignal).
 *
 * WHAT CHANGED BEYOND THE SIGNATURE
 * The original called Redis::incr() directly through the facade. That couples
 * a scoring signal to one cache driver: on a deployment whose CACHE_STORE is
 * database or array — which is every local and every CI run — the call does not
 * fail, it silently does nothing, and the signal reports clean on every
 * request. It now goes through the Cache contract, which is configured
 * per-environment and is what every other signal in this directory uses.
 *
 * WHY THE COUNTER IS SELF-CONTAINED HERE
 * Unlike the authentication-failure counter, this one has no natural producer
 * upstream: the fact being counted is "this address is talking to us fast", and
 * the only place that is observable is the request pipeline itself. The signal
 * therefore both observes and counts, which is why it must fail open — a burst
 * counter that throws would turn a cache blip into a site-wide outage.
 */
final class RequestVelocitySignal implements BotSignal
{
    /**
     * The sliding window. Deliberately short: this signal is about bursts, and
     * a long window would make ordinary browsing look like a burst.
     */
    private const WINDOW_SECONDS = 5;

    /**
     * Requests inside the window before the signal reports anything.
     */
    private const HIGH_BURST_THRESHOLD = 20;

    /**
     * Requests inside the window at which the signal reports full strength.
     */
    private const HIGH_BURST_CEILING = 60;

    public function score(Request $request): SignalResult
    {
        $identifier = (string) $request->ip();

        if ($identifier === '') {
            return new SignalResult('request_velocity', 0);
        }

        try {
            $key = $this->cacheKey($identifier);

            // Seed with a TTL before the first increment. Cache::increment()
            // creates a key with no expiry when it does not exist, and an
            // unbounded counter would accumulate across the whole deployment and
            // eventually report a burst for everybody.
            if (! Cache::has($key)) {
                Cache::put($key, 0, self::WINDOW_SECONDS);
            }

            $count = Cache::increment($key);
        } catch (\Throwable) {
            // Fail open: see the class docblock.
            return new SignalResult('request_velocity', 0);
        }

        if ($count <= self::HIGH_BURST_THRESHOLD) {
            return new SignalResult('request_velocity', 0);
        }

        $span = self::HIGH_BURST_CEILING - self::HIGH_BURST_THRESHOLD;
        $strength = (int) min(
            100,
            (int) round((($count - self::HIGH_BURST_THRESHOLD) / max(1, $span)) * 100),
        );

        return new SignalResult('request_velocity', max(1, $strength), ['BURST_REQUEST_VELOCITY_EXCEEDED']);
    }

    private function cacheKey(string $identifier): string
    {
        return 'bot:velocity:ip:'.$identifier;
    }
}
