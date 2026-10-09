<?php

declare(strict_types=1);

namespace App\Services\Lottery\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The tunables of one circuit breaker, resolved once from configuration.
 *
 * WHY THIS IS AN OBJECT AND NOT FOUR ARGUMENTS
 * The breaker reads the same four numbers on every call — allows(),
 * recordFailure(), recordSuccess() and describe() all take the policy — and
 * resolving them from config at each call site is how a cooldown ends up being
 * 120 seconds in one method and 60 in another. One resolution, one object, one
 * answer.
 *
 * IT IS IMMUTABLE. A breaker whose policy changed between a failure being
 * recorded and the same failure being counted would escalate on different
 * thresholds within a single circuit. Construct a new one to change a policy.
 */
final class BreakerPolicy
{
    public function __construct(
        public readonly bool $enabled,
        public readonly int $failureThreshold,
        public readonly int $cooldownSeconds,
        public readonly int $halfOpenProbes,
        /**
         * The window the threshold is understood over, used for the cache TTL
         * rather than for a rolling count: this breaker counts CONSECUTIVE
         * failures, not failures per unit time. A rolling window would need a
         * sliding structure to be correct, and a wrong sliding window is worse
         * than an honest consecutive count — it would drop the failure that
         * matters, in a burst, at random.
         */
        public readonly int $windowSeconds = 300,
        public readonly string $keyPrefix = 'glo:breaker:',
    ) {}

    /**
     * Read the breaker policy for a named lane.
     *
     * Defaults are deliberately conservative and deliberately NOT zero:
     *   - enabled: true   — an absent breaker is not a safe default for a
     *                       scheduled command that hangs on a dead upstream.
     *   - threshold: 3    — one failure is a blip, three is a pattern.
     *   - cooldown: 120s  — long enough for a real upstream reset, short enough
     *                       that a result is not held up for a human.
     *   - probes: 1       — see CircuitBreaker's docblock on why this is not
     *                       unlimited.
     */
    public static function fromConfig(ConfigRepository $config, string $lanePrefix = 'glo'): self
    {
        $prefix = $lanePrefix.'.sources.breaker.';

        return new self(
            enabled: (bool) $config->get($prefix.'enabled', true),
            failureThreshold: max(1, (int) $config->get($prefix.'failure_threshold', 3)),
            cooldownSeconds: max(1, (int) $config->get($prefix.'cooldown_seconds', 120)),
            halfOpenProbes: max(1, (int) $config->get($prefix.'half_open_probes', 1)),
            windowSeconds: max(1, (int) $config->get($prefix.'window_seconds', 300)),
            keyPrefix: (string) $config->get($prefix.'key_prefix', 'glo:breaker:'),
        );
    }

    /**
     * A policy that never refuses anything. Used by tests that want the provider
     * chain's FALLBACK behaviour without the breaker's state getting in the way,
     * and by any caller that has already established the source is healthy.
     */
    public static function disabled(): self
    {
        return new self(
            enabled: false,
            failureThreshold: PHP_INT_MAX,
            cooldownSeconds: 1,
            halfOpenProbes: 1,
        );
    }
}
