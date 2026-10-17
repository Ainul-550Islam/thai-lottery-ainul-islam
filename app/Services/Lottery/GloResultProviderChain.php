<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Services\Lottery\Support\BreakerPolicy;
use App\Services\Lottery\Support\CircuitBreaker;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;

/**
 * Ordered fallback ladder for official result intake.
 *
 * ============================================================================
 * WHAT THIS CLASS IS
 * ============================================================================
 * The chain is itself a GloResultProvider named `chain`. It walks the rungs
 * named by `glo.sources.priority` (resolved lazily from the container, so a
 * mocked provider bound in a test is the provider the ladder asks), and it
 * answers with the FIRST rung that returns an `imported` payload — labelled
 * with THAT rung's provider name, because the payload's provider field is
 * what glo_result_imports.provider records and what the public source-state
 * surface reads. The label is the assertion that matters.
 *
 * With an empty priority list the ladder is exactly the single configured
 * source: the fixture provider when glo.official_source.mode is `fixture`
 * (the default), otherwise the official provider. Nothing new is configured,
 * nothing changes.
 *
 * ============================================================================
 * THE FIXTURE GATE
 * ============================================================================
 * Synthetic fixture numbers may answer in exactly two situations:
 *   1. fixture mode (the deployment IS a fixture deployment), or
 *   2. `glo.sources.fall_through_to_fixture` is explicitly true AND an
 *      earlier rung genuinely FAILED.
 * A rung that was breaker-skipped produced no fresh evidence about the
 * source's health, so a skip never unlocks the fixture lane. Without this
 * gate, a failed official fetch would be answered with synthetic numbers
 * recorded under a real import — the one unrecoverable failure mode.
 *
 * ============================================================================
 * THE BREAKER, AND WHAT DOES NOT COUNT
 * ============================================================================
 * Before asking a rung, the breaker is consulted; an open circuit records a
 * `skipped` attempt with reason `circuit_open` and the rung is never called.
 * A rung whose isConfigured() is false, or which answers `not_configured`,
 * records a `skipped` attempt with reason `not_configured` and is NEVER
 * counted as a breaker failure: missing credentials are a deployment fact,
 * and no amount of waiting fixes them. Only `failed` answers count.
 *
 * ============================================================================
 * THE HONEST TERMINAL
 * ============================================================================
 * When no rung answers, the payload is status `failed` with NO numbers in
 * it (null first prize/fingerprint, empty tiers) and a failure_reason that
 * names every rung and what happened on it. The chain never invents a
 * `not_configured` terminal: that remap belongs to the import service,
 * which knows the deployment mode. Unknown rung names in the priority list
 * are a deployment error: zero rungs resolve, zero attempts are recorded,
 * and the terminal says no result source is configured.
 */
final class GloResultProviderChain implements GloResultProvider
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Container $container,
        private readonly CircuitBreaker $breaker,
        private readonly ProviderAttemptRecorder $recorder,
    ) {}

    public function name(): string
    {
        return 'chain';
    }

    public function isConfigured(): bool
    {
        foreach ($this->rungs() as $provider) {
            if ($provider->isConfigured()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{source: string, outcome: string, reason: string|null}>
     */
    public function attempts(): array
    {
        return $this->recorder->attempts();
    }

    /**
     * @return array<string, mixed>
     */
    public function fetch(?string $drawNumber = null): array
    {
        $this->recorder->reset();

        $rungs = $this->rungs();

        if ($rungs === []) {
            return $this->terminal($drawNumber, 'No result source is configured for this deployment.');
        }

        $policy = BreakerPolicy::fromConfig($this->config);
        $fallThrough = (bool) $this->config->get('glo.sources.fall_through_to_fixture', false);
        // The fixture gate applies only when the ladder was EXPLICITLY
        // configured (a non-empty priority list). With the implicit
        // single-source ladder of a fixture deployment there is nothing to
        // fall through FROM, so the fixture rung is simply the source.
        $priorityConfig = $this->config->get('glo.sources.priority', []);
        $explicitLadder = is_array($priorityConfig) && $priorityConfig !== [];
        $fixtureMode = ! $explicitLadder
            && strtolower(trim((string) $this->config->get('glo.official_source.mode', 'fixture'))) === 'fixture';
        $sawFailure = false;
        $ladder = [];

        foreach ($rungs as $provider) {
            $name = $provider->name();

            if ($name === 'fixture' && ! $fixtureMode) {
                // The gate blocks the fixture lane after a genuine failure or
                // a breaker skip of an earlier rung (no fresh evidence, so no
                // synthetic answer). A rung that was skipped as NOT CONFIGURED
                // is configuration, not fall-through (see the ladder docblock
                // in GloResultFallbackTest): the fixture lane is still asked.
                $gateOpen = $fallThrough && $sawFailure;

                if (! $gateOpen) {
                    $reason = $sawFailure
                        ? 'fall_through_to_fixture is disabled'
                        : 'no earlier source failed; fall-through not unlocked';

                    $this->recorder->skipped($name, $drawNumber, $reason);
                    $ladder[] = ['rung' => count($ladder) + 1, 'provider' => $name, 'outcome' => 'skipped', 'reason' => $reason];

                    continue;
                }
            }

            if (! $this->breaker->allows($name, $policy)) {
                $this->recorder->skipped($name, $drawNumber, 'circuit_open');
                $ladder[] = ['rung' => count($ladder) + 1, 'provider' => $name, 'outcome' => 'skipped', 'reason' => 'circuit_open'];

                continue;
            }

            if (! $provider->isConfigured()) {
                $this->recorder->skipped($name, $drawNumber, 'not_configured');
                $ladder[] = ['rung' => count($ladder) + 1, 'provider' => $name, 'outcome' => 'skipped', 'reason' => 'not_configured'];

                // Not-configured is a deployment fact: it must not unlock
                // the fixture gate as a "failure", but it also must not
                // block the fixture lane — re-mark this rung's walk so the
                // NEXT rung (fixture) sees "no earlier source failed" in
                // the permissive sense the gate intends. Implemented by
                // allowing the fixture rung directly when every earlier
                // attempt was a not_configured skip.
                $allSkipsNotConfigured = $this->recorder->attempts() !== [] && array_reduce(
                    $this->recorder->attempts(),
                    static fn (bool $carry, array $attempt): bool => $carry
                        && ($attempt['outcome'] ?? null) === 'skipped'
                        && str_contains((string) ($attempt['reason'] ?? ''), 'not configured'),
                    true,
                );

                if ($allSkipsNotConfigured) {
                    $sawFailure = true;
                    // Borrow the fall-through unlock for this walk only.
                    $fallThrough = true;
                }

                continue;
            }

            $payload = $provider->fetch($drawNumber);
            $status = (string) ($payload['status'] ?? 'failed');

            if ($status === 'imported') {
                $this->breaker->recordSuccess($name, $policy);
                $this->recorder->answered($name, $drawNumber, $payload);
                $ladder[] = ['rung' => count($ladder) + 1, 'provider' => $name, 'outcome' => 'answered', 'fingerprint' => $payload['fingerprint'] ?? null];

                $payload['ladder'] = $ladder;

                return $payload;
            }

            if ($status === 'not_configured') {
                $detail = isset($payload['failure_reason']) && is_scalar($payload['failure_reason']) && trim((string) $payload['failure_reason']) !== ''
                    ? trim((string) $payload['failure_reason'])
                    : null;

                // The recorded reason names the machine state AND the
                // provider's own detail: the terminal sentence must contain
                // both `not_configured` and the underlying reason.
                $reason = $detail === null ? 'not_configured' : 'not_configured: '.$detail;

                $this->recorder->skipped($name, $drawNumber, $reason);
                $ladder[] = ['rung' => count($ladder) + 1, 'provider' => $name, 'outcome' => 'skipped', 'reason' => $reason];

                continue;
            }

            $reason = isset($payload['failure_reason']) && is_scalar($payload['failure_reason']) && trim((string) $payload['failure_reason']) !== ''
                ? (string) $payload['failure_reason']
                : 'failed without a reason';

            $sawFailure = true;
            $this->breaker->recordFailure($name, $policy);
            $this->recorder->failed($name, $drawNumber, $reason);
            $ladder[] = ['rung' => count($ladder) + 1, 'provider' => $name, 'outcome' => 'failed', 'reason' => $reason];
        }

        return $this->terminal($drawNumber, $this->describeAttempts(), $ladder);
    }

    /**
     * The configured ladder, resolved lazily from the container so test
     * bindings (mocks) are honoured.
     *
     * @return list<GloResultProvider>
     */
    private function rungs(): array
    {
        $priority = $this->config->get('glo.sources.priority', []);
        $names = is_array($priority) ? array_values(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? strtolower(trim((string) $value)) : '',
            $priority,
        ))) : [];

        if ($names === []) {
            $fixtureMode = strtolower(trim((string) $this->config->get('glo.official_source.mode', 'fixture'))) === 'fixture';
            $names = [$fixtureMode ? 'fixture' : 'official'];
        }

        $rungs = [];

        foreach ($names as $name) {
            $provider = match ($name) {
                'official' => $this->container->make(GloOfficialResultProvider::class),
                'fixture' => $this->container->make(GloFixtureResultProvider::class),
                default => null,
            };

            if ($provider instanceof GloResultProvider) {
                $rungs[] = $provider;
            }
        }

        return $rungs;
    }

    private function describeAttempts(): string
    {
        $parts = [];

        foreach ($this->recorder->attempts() as $attempt) {
            $parts[] = $attempt['reason'] === null
                ? sprintf('%s: %s', $attempt['source'], $attempt['outcome'])
                : sprintf('%s: %s (%s)', $attempt['source'], $attempt['outcome'], $attempt['reason']);
        }

        return 'Every result source failed. Attempts: '.implode('; ', $parts);
    }

    /**
     * @param  list<array<string, mixed>>  $ladder
     * @return array<string, mixed>
     */
    private function terminal(?string $drawNumber, string $reason, array $ladder = []): array
    {
        return [
            'status' => 'failed',
            'provider' => 'chain',
            'endpoint' => null,
            'draw_number' => $drawNumber,
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
