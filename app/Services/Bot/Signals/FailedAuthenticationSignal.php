<?php

declare(strict_types=1);

namespace App\Services\Bot\Signals;

use App\Services\Bot\Contracts\BotSignal;
use App\Services\Bot\DTOs\SignalResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Counts recent failed logins from one client IP.
 *
 * B1 FIX — THIS CLASS DID NOT IMPLEMENT ITS OWN INTERFACE.
 *
 * BotSignal declares:
 *
 *     public function score(Request $request): SignalResult;
 *
 * This class instead provided getKey(), getWeight() and
 * evaluate(Request $request, array $context = []): array — the shape of an
 * earlier revision of the interface. It is a concrete class, so PHP rejects it
 * at CLASS LOAD, not at call time:
 *
 *     Fatal error: Class FailedAuthenticationSignal contains 1 abstract method
 *     and must therefore be declared abstract or implement the remaining
 *     methods (BotSignal::score)
 *
 * Nothing catches a fatal. The reason this never took production down is that
 * nothing ever loaded the class: BotServiceProvider's signal registry has a
 * `match` arm for five signals and this was never one of them, and
 * BotDetectionService only walks config('bot.signals'). So the defect was
 * latent — a landmine that detonates the moment anyone adds the signal to
 * config or instantiates it in a test.
 *
 * B1 FIX — THE COUNTER HAD NO PRODUCER.
 *
 * The original read a Redis key `auth:failures:ip:{ip}` that no code anywhere
 * in the repository ever wrote. Porting the signature alone would have produced
 * a signal that is legal but permanently silent: it would read zero forever and
 * report "no evidence", which is worse than no signal because it looks like
 * coverage. The producer is now in
 * AuthenticationSecurityService::loginFailed(), which is the one place every
 * failed login already passes through.
 *
 * WHY THIS IS WEAK EVIDENCE
 * A shared NAT, a corporate proxy or a mobile carrier can put hundreds of
 * legitimate members behind one address, so a spike here is a reason to look
 * and never a reason to block on its own. Its configured mode is therefore
 * 'log', and the weight is deliberately modest.
 */
final class FailedAuthenticationSignal implements BotSignal
{
    /**
     * How long one failure stays inside the counting window. Matches
     * AuthenticationSecurityService::LOCKOUT_WINDOW_MINUTES so the bot signal
     * and the lockout cannot disagree about what "recent" means.
     */
    private const FAIL_WINDOW_SECONDS = 900; // 15 minutes

    /**
     * Failures from one address before the signal reports anything at all.
     */
    private const FAIL_THRESHOLD = 5;

    /**
     * Failures at which the signal reports full strength.
     */
    private const FAIL_CEILING = 15;

    public function score(Request $request): SignalResult
    {
        $identifier = (string) $request->ip();

        if ($identifier === '') {
            // No address means no evidence either way. Reporting strength here
            // would punish every request whose IP could not be determined.
            return new SignalResult('failed_auth', 0);
        }

        try {
            $failures = (int) Cache::get($this->cacheKey($identifier), 0);
        } catch (\Throwable) {
            // FAIL OPEN. Bot scoring is a control that informs, never one that
            // authorises: if evidence is unavailable the honest answer is "no
            // evidence", and a member must not be blocked by an outage in a
            // signal's data source.
            return new SignalResult('failed_auth', 0);
        }

        if ($failures <= self::FAIL_THRESHOLD) {
            return new SignalResult('failed_auth', 0);
        }

        // Ramp linearly from the threshold to the ceiling rather than jumping to
        // 100 at the first breach: five failures and fourteen failures are not
        // the same fact, and a binary signal gives the operator nothing to tune.
        $span = self::FAIL_CEILING - self::FAIL_THRESHOLD;
        $strength = (int) min(
            100,
            (int) round((($failures - self::FAIL_THRESHOLD) / max(1, $span)) * 100),
        );

        return new SignalResult('failed_auth', max(1, $strength), ['REPEATED_AUTHENTICATION_FAILURES']);
    }

    /**
     * Increment the failure counter for one client address.
     *
     * Called by AuthenticationSecurityService::loginFailed(). Public because a
     * producer that lives behind the same interface as the reader is the only
     * way the key format stays in one place — if the reader and the writer each
     * built the string, they would drift, and a drift here is silent.
     *
     * @internal
     */
    public static function recordFailure(string $ipAddress): void
    {
        if ($ipAddress === '') {
            return;
        }

        try {
            $key = 'auth:failures:ip:'.$ipAddress;

            // Seed before incrementing so the window is attached to the first
            // failure. Cache::increment() alone would create a key with no TTL,
            // and a counter that never expires turns one bad afternoon into a
            // permanent block.
            if (! Cache::has($key)) {
                Cache::put($key, 0, self::FAIL_WINDOW_SECONDS);
            }

            Cache::increment($key);
        } catch (\Throwable) {
            // A bot-signal counter is never worth failing a login over.
        }
    }

    private function cacheKey(string $identifier): string
    {
        return 'auth:failures:ip:'.$identifier;
    }
}
