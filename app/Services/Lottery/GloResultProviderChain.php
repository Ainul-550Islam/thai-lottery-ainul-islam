<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Services\Lottery\Contracts\ResultSource;

/**
 * Ordered fallback ladder for official result intake.
 *
 * ============================================================================
 * THE GAP THIS CLOSES
 * ============================================================================
 * The repository had exactly two providers — GloFixtureResultProvider and
 * GloOfficialResultProvider — selected by a single `mode` string:
 *
 *     return $mode === 'official' ? $this->officialProvider : $this->fixtureProvider;
 *
 * That is a switch, not a ladder. If the official endpoint is down, times out,
 * or answers with a changed schema, the import records a failure and STOPS.
 * There is no secondary source, and there is no manual signed-ingestion path,
 * which is the safety valve a regulated lottery operator needs when the
 * authoritative feed is unreachable on draw night.
 *
 * ============================================================================
 * THE LADDER
 * ============================================================================
 *   1. Official GLO endpoint        (authoritative, primary)
 *   2. Certified secondary gateway  (independent transport, same authority)
 *   3. Manual signed ingestion      (a human operator, a committed payload,
 *                                    an offline signature — last resort)
 *
 * ORDERING IS BY AUTHORITY, NOT BY HEALTH. A healthy secondary is never allowed
 * to answer while an unhealthy primary might still recover, because "the
 * secondary said so and we published it" is how two different official numbers
 * reach the public in one day. The ladder only advances when a rung has
 * EXHAUSTED its retries and is affirmatively unavailable.
 *
 * A FIXTURE IS NEVER A RUNG. Fixture data exists for development and for tests.
 * ProductionSafetyServiceProvider refuses to boot production with a fixture lane
 * enabled, and this class will not consult one. A fabricated number reaching a
 * paying player is the one failure mode that is not recoverable by an apology.
 *
 * THE CIRCUIT BREAKER
 * A provider that has just failed N times in a row is not asked again for a
 * cool-off period. Without this, every import during an outage pays the full
 * connection timeout on the dead primary before falling through, which turns a
 * provider outage into a queue backlog.
 *
 * AUDIT: every rung attempted is recorded, including the ones that were skipped
 * and why. "Which source answered" is a question that must be answerable after
 * the fact without reading logs.
 */
final class GloResultProviderChain
{
    /** @var list<ResultSource> */
    private array $rungs = [];

    public function __construct(
        private readonly CircuitBreaker $breaker,
        private readonly ProviderAttemptRecorder $recorder,
    ) {}

    /**
     * Register a rung. Registration order is authority order.
     */
    public function add(ResultSource $source): self
    {
        $this->rungs[] = $source;

        return $this;
    }

    /**
     * Fetch the result for a draw number, walking the ladder.
     *
     * @return array{
     *     status: string,
     *     provider: string,
     *     endpoint: string|null,
     *     draw_number: string|null,
     *     first_prize: string|null,
     *     second_prize: list<string>,
     *     third_prize: list<string>,
     *     n3: array<string, list<string>>,
     *     tiers: array<string, list<string>>,
     *     failure_reason: string|null,
     *     fingerprint: string|null,
     *     ladder: list<array<string, mixed>>
     * }
     */
    public function fetch(?string $drawNumber = null): array
    {
        if ($this->rungs === []) {
            return $this->terminal('No result source is configured on the ladder.');
        }

        $ladder = [];
        $lastFailure = null;

        foreach ($this->rungs as $index => $source) {
            $name = $source->name();

            // ── Circuit breaker: skip a rung that is in its cool-off window.
            if ($this->breaker->isOpen($name)) {
                $ladder[] = [
                    'rung' => $index + 1,
                    'provider' => $name,
                    'outcome' => 'skipped',
                    'reason' => 'circuit_open',
                    'retry_after_seconds' => $this->breaker->retryAfterSeconds($name),
                ];

                $this->recorder->skipped($name, $drawNumber, 'circuit_open');

                continue;
            }

            if (! $source->isConfigured()) {
                $ladder[] = [
                    'rung' => $index + 1,
                    'provider' => $name,
                    'outcome' => 'skipped',
                    'reason' => 'not_configured',
                ];

                $this->recorder->skipped($name, $drawNumber, 'not_configured');

                continue;
            }

            $payload = $source->fetch($drawNumber);
            $status = (string) ($payload['status'] ?? 'failed');

            // ── A rung only answers when it has an AUTHORITATIVE result.
            if ($status === 'imported' && $this->isUsable($payload)) {
                $this->breaker->recordSuccess($name);

                $ladder[] = [
                    'rung' => $index + 1,
                    'provider' => $name,
                    'outcome' => 'answered',
                    'fingerprint' => $payload['fingerprint'] ?? null,
                ];

                $this->recorder->answered($name, $drawNumber, $payload);

                $payload['ladder'] = $ladder;

                return $payload;
            }

            // ── Anything else is a failure of this rung: advance.
            $reason = (string) ($payload['failure_reason'] ?? $status);
            $lastFailure = $reason;

            $this->breaker->recordFailure($name, $reason);

            $ladder[] = [
                'rung' => $index + 1,
                'provider' => $name,
                'outcome' => 'failed',
                'reason' => $reason,
            ];

            $this->recorder->failed($name, $drawNumber, $reason);
        }

        // ── Every rung refused. This is an HONEST NEGATIVE: no number is
        //    returned, nothing is written, and the operator is told which
        //    sources were tried and how each one failed. A fabricated or
        //    stale result would be strictly worse than no result.
        return $this->terminal(
            $lastFailure === null
                ? 'No configured result source answered.'
                : 'Every source on the ladder failed; last reason: '.$lastFailure,
            $ladder,
        );
    }

    /**
     * A rung only counts as answering when it carries a fingerprint and a
     * well-formed first prize. Without a fingerprint the result cannot be
     * pinned, and the four-eyes confirmation has nothing to attest to.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isUsable(array $payload): bool
    {
        $fingerprint = trim((string) ($payload['fingerprint'] ?? ''));
        $firstPrize = trim((string) ($payload['first_prize'] ?? ''));

        return $fingerprint !== ''
            && $firstPrize !== ''
            && preg_match('/^\d{6}$/', $firstPrize) === 1;
    }

    /**
     * @param  list<array<string, mixed>>  $ladder
     * @return array<string, mixed>
     */
    private function terminal(string $reason, array $ladder = []): array
    {
        return [
            'status' => 'failed',
            'provider' => 'ladder',
            'endpoint' => null,
            'draw_number' => null,
            'first_prize' => null,
            'second_prize' => [],
            'third_prize' => [],
            'n3' => [],
            'tiers' => [],
            'failure_reason' => $reason,
            'fingerprint' => null,
            'ladder' => $ladder,
        ];
    }
}
