<?php

declare(strict_types=1);

namespace App\Services\Lottery\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

/**
 * Per-source circuit breaker for outbound result-provider fetches.
 *
 * ============================================================================
 * WHAT PROBLEM THIS SOLVES, STATED PRECISELY
 * ============================================================================
 * GLO's official catalog endpoint is a third party. When it is slow, every
 * import attempt pays the full HTTP timeout before failing; when it is down,
 * every attempt pays it again. A draw's result import runs on a schedule, so a
 * dead upstream means a scheduled command that hangs for `request_timeout_seconds`
 * on every tick, and a log full of identical timeouts that hides the first one.
 *
 * A breaker turns that into one slow failure followed by fast, explicit
 * refusals: the source is known-bad, we stop asking, and we say so — until a
 * cooled-down probe proves otherwise.
 *
 * ============================================================================
 * THREE STATES, AND WHY THE HALF-OPEN STATE HAS A PROBE LIMIT
 * ============================================================================
 *   CLOSED     normal. Failures are counted; at the threshold the circuit OPENS.
 *   OPEN       refusals are returned IMMEDIATELY without a network call, until
 *              the cooldown expires. Then it becomes HALF-OPEN.
 *   HALF_OPEN  exactly `half_open_probes` attempts are allowed through. A
 *              success closes the circuit; a failure re-opens it and restarts
 *              the cooldown.
 *
 * The probe limit is the part that is usually got wrong. Without it, half-open
 * means "the floodgates are open until one request happens to succeed" — so a
 * still-broken upstream receives the full traffic volume again at the exact
 * moment it is least able to take it. That is the opposite of what a breaker is
 * for.
 *
 * ============================================================================
 * WHY A FAILURE IS ONLY COUNTED FOR A GENUINE UPSTREAM FAULT
 * ============================================================================
 * A payload with `status = not_configured` is NOT an upstream failure: it means
 * THIS deployment has no credentials, which no amount of waiting will fix, and
 * which opening a circuit would hide behind "the source is down". A `failed`
 * payload is counted, because it is the provider telling us the fetch did not
 * work. The decision is made by the caller, which knows which it received —
 * see recordFailure()'s contract.
 *
 * ============================================================================
 * WHERE THE STATE LIVES, AND THE HONEST LIMITATION
 * ============================================================================
 * State is held in the configured cache because a breaker is shared knowledge:
 * if each container kept its own copy, a three-container deployment would need
 * three times the threshold before anything opened, and the state would reset on
 * every deploy.
 *
 * This is therefore only correct on a SHARED cache store (redis, database). On
 * the file store it degrades to a per-container breaker — degraded, not
 * dangerous, and `ProductionSafetyServiceProvider` refuses a file cache in
 * production for reasons that subsume this one. If the cache is unavailable the
 * breaker FAILS CLOSED (treats the circuit as closed and attempts the fetch),
 * because a breaker that cannot read its own state must not become a second
 * outage: the worst case is that we pay a timeout we would have avoided.
 */
final class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';

    private const STATE_OPEN = 'open';

    private const STATE_HALF_OPEN = 'half_open';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Whether a fetch may be attempted for this source right now.
     *
     * Side-effecting on purpose: in HALF_OPEN this CLAIMS one of the probe slots
     * and returns true for it, so two concurrent callers cannot both be told
     * "you may probe" when only one probe is configured. Claiming at the moment
     * of permission is what makes the limit real.
     */
    public function allows(string $source, BreakerPolicy $policy): bool
    {
        if (! $policy->enabled) {
            return true;
        }

        try {
            return $this->withMutex($source, $policy, function () use ($source, $policy): bool {
                $state = $this->state($source, $policy);

                if ($state['state'] === self::STATE_CLOSED) {
                    return true;
                }

                if ($state['state'] === self::STATE_OPEN) {
                    $openedAt = $state['opened_at'];

                    if ($openedAt !== null && Carbon::parse($openedAt)->addSeconds($policy->cooldownSeconds)->isFuture()) {
                        return false;
                    }

                    // Cooldown elapsed: initialize the bounded half-open probes
                    // while holding the distributed lock.
                    $state['state'] = self::STATE_HALF_OPEN;
                    $state['probes_taken'] = 0;
                    $state['probe_started_at'] = now()->toIso8601String();
                } elseif ($state['state'] === self::STATE_HALF_OPEN
                    && (int) $state['probes_taken'] >= $policy->halfOpenProbes) {
                    // Recover a half-open slot if the worker that claimed it
                    // died before recording success or failure. Provider calls
                    // have bounded timeouts; this lease is deliberately longer
                    // than the minimum timeout to avoid an early duplicate.
                    $probeStartedAt = $state['probe_started_at'] ?? null;
                    $leaseSeconds = max(60, $policy->cooldownSeconds);

                    if (is_string($probeStartedAt)
                        && Carbon::parse($probeStartedAt)->addSeconds($leaseSeconds)->isFuture()) {
                        return false;
                    }

                    $state['probes_taken'] = 0;
                    $state['probe_started_at'] = now()->toIso8601String();
                }

                if ((int) $state['probes_taken'] >= $policy->halfOpenProbes) {
                    return false;
                }

                $state['probes_taken'] = (int) $state['probes_taken'] + 1;
                $this->write($source, $state, $policy);

                return true;
            });
        } catch (\Throwable) {
            // Fail closed toward ATTEMPTING. See the class docblock: an unreadable
            // breaker must not refuse traffic on its own authority.
            return true;
        }
    }

    /**
     * Record that a fetch failed in a way that indicates the SOURCE is unhealthy.
     *
     * Must NOT be called for `not_configured`: that is a deployment fact, not an
     * upstream fault, and counting it would open the circuit on a system that was
     * never reachable in the first place.
     */
    public function recordFailure(string $source, BreakerPolicy $policy, string $reason = ''): void
    {
        if (! $policy->enabled) {
            return;
        }

        try {
            $this->withMutex($source, $policy, function () use ($source, $policy, $reason): void {
                $state = $this->state($source, $policy);
                $state['failures'] = (int) $state['failures'] + 1;
                $state['last_failure_at'] = now()->toIso8601String();
                $state['last_reason'] = $reason;
                $state['probes_taken'] = 0;
                $state['probe_started_at'] = null;

                if ($state['state'] === self::STATE_HALF_OPEN) {
                    // A failed probe re-opens the circuit and restarts cooldown.
                    $state['state'] = self::STATE_OPEN;
                    $state['opened_at'] = now()->toIso8601String();
                    $this->write($source, $state, $policy);

                    return;
                }

                if ($state['failures'] >= $policy->failureThreshold) {
                    $state['state'] = self::STATE_OPEN;
                    $state['opened_at'] = now()->toIso8601String();
                }

                $this->write($source, $state, $policy);
            });
        } catch (\Throwable) {
            // A breaker that cannot record a failure simply stays closed. The
            // fetch path already knows the fetch failed and will report it; the
            // breaker is an optimisation, not the source of truth about health.
        }
    }

    /**
     * Record that a fetch succeeded. Closes the circuit and clears the counters.
     */
    public function recordSuccess(string $source, BreakerPolicy $policy): void
    {
        if (! $policy->enabled) {
            return;
        }

        try {
            $this->withMutex($source, $policy, function () use ($source, $policy): void {
                $this->write($source, [
                    'state' => self::STATE_CLOSED,
                    'failures' => 0,
                    'opened_at' => null,
                    'last_success_at' => now()->toIso8601String(),
                    'last_failure_at' => null,
                    'probes_taken' => 0,
                    'probe_started_at' => null,
                ], $policy);
            });
        } catch (\Throwable) {
            // Nothing to do and nothing to report: the next success will write
            // the closed state again.
        }
    }

    /**
     * The current state, for operators and tests. Never mutates.
     *
     * @return array{state: string, failures: int, opened_at: string|null, retry_after_seconds: int, last_success_at: string|null, last_failure_at: string|null}
     */
    public function describe(string $source, BreakerPolicy $policy): array
    {
        $state = $this->state($source, $policy);

        $retryAfter = 0;

        if ($state['state'] === self::STATE_OPEN && is_string($state['opened_at'])) {
            $elapsed = Carbon::parse($state['opened_at'])->diffInSeconds(now());
            $retryAfter = max(0, $policy->cooldownSeconds - (int) $elapsed);
        }

        return [
            'state' => (string) $state['state'],
            'failures' => (int) $state['failures'],
            'opened_at' => is_string($state['opened_at']) ? $state['opened_at'] : null,
            'retry_after_seconds' => $retryAfter,
            'last_success_at' => is_string($state['last_success_at']) ? $state['last_success_at'] : null,
            'last_failure_at' => is_string($state['last_failure_at']) ? $state['last_failure_at'] : null,
        ];
    }

    /**
     * Clear a source's state. For an operator who knows the upstream is back and
     * does not want to wait out the cooldown.
     */
    public function reset(string $source, BreakerPolicy $policy): void
    {
        try {
            $this->withMutex($source, $policy, function () use ($source, $policy): void {
                $this->cache->forget($this->key($source, $policy));
            });
        } catch (\Throwable) {
            // Nothing to do.
        }
    }

    /**
     * @return array{state: string, failures: int, opened_at: string|null, last_success_at: string|null, last_failure_at: string|null, probes_taken: int, probe_started_at: string|null}
     */
    private function state(string $source, BreakerPolicy $policy): array
    {
        $stored = $this->cache->get($this->key($source, $policy));

        if (! is_array($stored)) {
            return [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'opened_at' => null,
                'last_success_at' => null,
                'last_failure_at' => null,
                'probes_taken' => 0,
                'probe_started_at' => null,
            ];
        }

        return array_merge([
            'state' => self::STATE_CLOSED,
            'failures' => 0,
            'opened_at' => null,
            'last_success_at' => null,
            'last_failure_at' => null,
            'probes_taken' => 0,
            'probe_started_at' => null,
        ], $stored);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function write(string $source, array $state, BreakerPolicy $policy): void
    {
        // The TTL is driven by the longest period the state needs to survive:
        // the cooldown plus a margin, and never less than the window in which
        // failures are counted. An entry that expires while the circuit is OPEN
        // silently re-closes it, which is precisely the fail-open behaviour this
        // class exists to avoid.
        $ttl = max(60, ($policy->cooldownSeconds * 2) + $policy->windowSeconds);

        $this->cache->put($this->key($source, $policy), $state, $ttl);
    }

    /**
     * Serialize state transitions across workers so simultaneous probes and
     * failures cannot overwrite each other's counters. The lock store must be
     * shared in a multi-instance deployment (Redis in the production Compose
     * stack); if lock acquisition fails, callers retain the class's documented
     * fail-open behavior for provider availability.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withMutex(string $source, BreakerPolicy $policy, callable $callback): mixed
    {
        $key = 'glo:breaker:mutex:'.sha1($policy->keyPrefix.'|'.$source);

        // Forward the lock operation through the same cache repository used
        // for state reads/writes. Calling the Cache facade here can resolve a
        // different store from an already-injected repository after config
        // changes (notably in tests and per-lane cache bindings).
        return $this->cache->lock($key, 10)->block(5, $callback);
    }

    private function key(string $source, ?BreakerPolicy $policy): string
    {
        $prefix = $policy?->keyPrefix ?? 'glo:breaker:';

        return $prefix.preg_replace('/[^a-z0-9_\-]/i', '_', $source);
    }
}
