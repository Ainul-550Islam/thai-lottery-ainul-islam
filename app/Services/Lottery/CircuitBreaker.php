<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * A minimal, provider-agnostic circuit breaker for the result ladder.
 *
 * WHY NOT JUST RETRY
 * A dead provider does not fail fast; it times out. During an outage, asking the
 * dead source first on every import costs the full connection timeout each time,
 * and the fallback that exists to protect availability instead becomes the thing
 * that exhausts the worker pool. Opening the circuit after N consecutive
 * failures, for a cool-off period, is what keeps the ladder's promise of
 * "something still answers".
 *
 * STATES
 *   closed    — normal. Failures are counted.
 *   open      — refusing. Set when consecutive failures reach the threshold.
 *   half-open — the cool-off has elapsed; exactly ONE probe is allowed through.
 *               A success closes the circuit; a failure re-opens it with the
 *               same cool-off. This is what makes recovery automatic without a
 *               deploy.
 *
 * The state lives in the cache, so every worker in every container shares one
 * view of provider health. A per-process breaker would mean each of twenty
 * workers independently rediscovered the outage.
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function threshold(): int
    {
        return max(1, (int) config('glo.ladder.circuit_breaker.failure_threshold', 5));
    }

    public function cooldownSeconds(): int
    {
        return max(1, (int) config('glo.ladder.circuit_breaker.cooldown_seconds', 120));
    }

    /**
     * Whether this provider is currently refusing traffic.
     *
     * An OPEN circuit whose cool-off has elapsed flips to half-open and allows
     * one probe: `isOpen()` returns false exactly once per cool-off window,
     * because the probe claim below is atomic.
     */
    public function isOpen(string $provider): bool
    {
        $state = $this->state($provider);

        if (($state['status'] ?? 'closed') === 'closed') {
            return false;
        }

        $openedAt = (int) ($state['opened_at'] ?? 0);

        if ((time() - $openedAt) < $this->cooldownSeconds()) {
            return true;
        }

        // Cool-off elapsed. Allow a single probe; Cache::add() is SET NX, so
        // exactly one caller becomes the probe even under concurrent workers.
        return ! $this->cache->add($this->probeKey($provider), 1, $this->cooldownSeconds());
    }

    public function retryAfterSeconds(string $provider): int
    {
        $state = $this->state($provider);
        $openedAt = (int) ($state['opened_at'] ?? time());

        return max(0, $this->cooldownSeconds() - (time() - $openedAt));
    }

    public function recordSuccess(string $provider): void
    {
        $this->cache->forget($this->stateKey($provider));
        $this->cache->forget($this->probeKey($provider));
    }

    public function recordFailure(string $provider, string $reason = ''): void
    {
        $state = $this->state($provider);
        $failures = (int) ($state['failures'] ?? 0) + 1;

        $status = 'closed';
        $openedAt = (int) ($state['opened_at'] ?? 0);

        if ($failures >= $this->threshold()) {
            $status = 'open';
            $openedAt = time();
        }

        $this->cache->put(
            $this->stateKey($provider),
            [
                'status' => $status,
                'failures' => $failures,
                'opened_at' => $openedAt,
                'last_reason' => $reason,
                'last_failure_at' => time(),
            ],
            // Keep the record well past the cool-off so `failures` still counts
            // consecutive failures across a probe cycle rather than resetting to
            // zero every time the window rolls off.
            now()->addSeconds($this->cooldownSeconds() * 10),
        );

        // A failed probe must not be retried immediately by another worker.
        $this->cache->forget($this->probeKey($provider));
    }

    /**
     * @return array<string, mixed>
     */
    public function state(string $provider): array
    {
        $state = $this->cache->get($this->stateKey($provider), []);

        return is_array($state) ? $state : [];
    }

    private function stateKey(string $provider): string
    {
        return 'glo:ladder:breaker:'.sha1($provider);
    }

    private function probeKey(string $provider): string
    {
        return 'glo:ladder:probe:'.sha1($provider);
    }
}
