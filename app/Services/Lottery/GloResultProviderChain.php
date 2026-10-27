<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Services\Lottery\Support\BreakerPolicy;
use App\Services\Lottery\Support\CircuitBreaker;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Ordered result-source ladder with circuit-breaker protection and explicit
 * fixture fall-through gating.
 */
final class GloResultProviderChain implements GloResultProvider
{
    /**
     * @var array<string, GloResultProvider>
     */
    private readonly array $providers;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly CircuitBreaker $breaker,
        private readonly ProviderAttemptRecorder $recorder,
        GloOfficialResultProvider $official,
        GloFixtureResultProvider $fixture,
    ) {
        $this->providers = [
            $official->name() => $official,
            $fixture->name() => $fixture,
        ];
    }

    public function name(): string
    {
        return 'chain';
    }

    public function isConfigured(): bool
    {
        foreach ($this->resolveOrder() as $name) {
            if ($this->providers[$name]->isConfigured()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{source: string, outcome: string, reason: ?string}>
     */
    public function attempts(): array
    {
        return $this->recorder->attempts();
    }

    /**
     * @return array{
     *   status: 'imported'|'not_configured'|'failed',
     *   provider: string,
     *   endpoint: ?string,
     *   draw_number: ?string,
     *   first_prize: ?string,
     *   bottom_two?: ?string,
     *   second_prize: list<string>,
     *   third_prize: list<string>,
     *   n3: array<string, mixed>,
     *   tiers: array<string, mixed>,
     *   failure_reason: ?string,
     *   fingerprint: ?string,
     *   attempts?: list<array{source: string, outcome: string, reason: ?string}>,
     *   provider_status?: string
     * }
     */
    public function fetch(?string $drawNumber = null): array
    {
        // The chain is resolved once and reused: without this reset a second
        // draw would inherit the first draw's rungs in `attempts()`.
        $this->recorder->reset();
        $order = $this->resolveOrder();

        if ($order === []) {
            return $this->terminal(
                provider: $this->name(),
                endpoint: null,
                drawNumber: $drawNumber,
                reason: 'No result source is configured in glo.sources.priority or glo.official_source.mode',
                providerStatus: 'failed',
            );
        }

        $policy = BreakerPolicy::fromConfig($this->config);
        $allowFixtureFallthrough = (bool) $this->config->get('glo.sources.fall_through_to_fixture', false);
        $hadUpstreamFailure = false;
        $lastEndpoint = null;
        $lastProvider = $this->name();
        $failureParts = [];
        $sawNonNotConfigured = false;
        $sawNotConfigured = false;

        // Set when the walk stops short of the fixture lane, so the failure
        // reason can say so instead of pretending the lane was never in the
        // ladder.
        $fixtureLaneWithheld = false;

        foreach ($order as $name) {
            $provider = $this->providers[$name];

            if ($name === 'fixture' && $hadUpstreamFailure && ! $allowFixtureFallthrough) {
                if (! $this->fixtureLaneIsEarned($policy)) {
                    $fixtureLaneWithheld = true;

                    break;
                }
            }

            if (! $provider->isConfigured()) {
                $this->recorder->skipped($name, $drawNumber, 'not_configured');
                $failureParts[] = "{$name} (not_configured)";
                $sawNotConfigured = true;

                continue;
            }

            if (! $this->breaker->allows($name, $policy)) {
                $state = $this->breaker->describe($name, $policy);
                $reason = sprintf('circuit open (retry after %ds)', $state['retry_after_seconds']);

                $this->recorder->skipped($name, $drawNumber, 'circuit_open');
                $failureParts[] = "{$name} ({$reason})";
                $hadUpstreamFailure = true;
                $sawNonNotConfigured = true;

                continue;
            }

            try {
                $payload = $provider->fetch($drawNumber);
            } catch (Throwable $e) {
                $this->breaker->recordFailure($name, $policy);
                $reason = $e->getMessage();

                $this->recorder->failed($name, $drawNumber, $reason);
                $failureParts[] = "{$name} (failed: {$reason})";
                $hadUpstreamFailure = true;
                $sawNonNotConfigured = true;
                $lastProvider = $name;

                continue;
            }

            $status = (string) ($payload['status'] ?? 'failed');
            $lastProvider = (string) ($payload['provider'] ?? $name);
            $lastEndpoint = isset($payload['endpoint']) ? (string) $payload['endpoint'] : $lastEndpoint;

            if ($status === 'imported') {
                $returnedDraw = isset($payload['draw_number']) ? (string) $payload['draw_number'] : null;

                if ($drawNumber !== null && $returnedDraw !== null && $returnedDraw !== '' && $returnedDraw !== $drawNumber) {
                    $this->breaker->recordFailure($name, $policy);
                    $reason = sprintf('draw mismatch: expected %s, got %s', $drawNumber, $returnedDraw);

                    $this->recorder->failed($name, $drawNumber, $reason);
                    $failureParts[] = "{$name} (failed: {$reason})";
                    $hadUpstreamFailure = true;
                    $sawNonNotConfigured = true;

                    continue;
                }

                $this->breaker->recordSuccess($name, $policy);

                $this->recorder->answered($name, $drawNumber, $payload);

                $payload['provider'] = $lastProvider;
                $payload['attempts'] = $this->recorder->attempts();

                return $payload;
            }

            $reason = isset($payload['failure_reason']) && (string) $payload['failure_reason'] !== ''
                ? (string) $payload['failure_reason']
                : $status;
            $isOutage = (bool) ($payload['outage'] ?? false);

            if ($status === 'not_configured' && ! $isOutage) {
                $this->recorder->record($name, $drawNumber, 'not_configured', $reason);
                $failureParts[] = "{$name} (not_configured: {$reason})";
                $sawNotConfigured = true;

                continue;
            }

            $this->breaker->recordFailure($name, $policy);

            $this->recorder->failed($name, $drawNumber, $reason);
            $failureParts[] = "{$name} ({$status}: {$reason})";
            $hadUpstreamFailure = true;

            if ($status === 'not_configured') {
                $sawNotConfigured = true;
            } else {
                $sawNonNotConfigured = true;
            }
        }

        if ($fixtureLaneWithheld) {
            // Named, with the reason, so the operator reading this row knows the
            // difference between "there is no fixture lane" and "we were not
            // allowed to use it".
            $failureParts[] = 'fixture (not attempted: fall_through_to_fixture is off and no source has declared itself unhealthy)';
        }

        $reason = $failureParts === []
            ? 'Every result source failed'
            : 'Every result source failed: '.implode('; ', $failureParts);

        return $this->terminal(
            provider: $lastProvider,
            endpoint: $lastEndpoint,
            drawNumber: $drawNumber,
            reason: $reason,
            providerStatus: ($sawNotConfigured && ! $sawNonNotConfigured) ? 'not_configured' : 'failed',
        );
    }

    /**
     * Whether the ladder has EARNED the fixture lane even though the deployment
     * did not permit fall-through.
     *
     * Two conditions, both factual:
     *
     *   * a source in this ladder is in an open circuit — the breaker has already
     *     recorded enough consecutive failures to declare it down, so this is a
     *     station outage rather than one unlucky request; and
     *   * this process is not production. Synthetic vectors exist so a rehearsal
     *     or a test run can finish the flow; they must never reach a player, so
     *     production refuses the lane outright no matter what the breaker says.
     *
     * Everything the lane returns is still labelled `fixture` in the payload, so
     * even when it answers, the provenance row says a test vector did.
     */
    private function fixtureLaneIsEarned(BreakerPolicy $policy): bool
    {
        if (strtolower(trim((string) $this->config->get('app.env', 'production'))) === 'production') {
            return false;
        }

        foreach (array_keys($this->providers) as $candidate) {
            if ($candidate === 'fixture') {
                continue;
            }

            if (($this->breaker->describe($candidate, $policy)['state'] ?? null) === 'open') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function resolveOrder(): array
    {
        $configured = $this->config->get('glo.sources.priority', []);

        if (is_string($configured)) {
            $configured = array_filter(array_map('trim', explode(',', $configured)));
        }

        $raw = [];

        if (is_array($configured) && $configured !== []) {
            foreach ($configured as $entry) {
                if (is_string($entry) || is_numeric($entry)) {
                    $normalized = strtolower(trim((string) $entry));
                    if ($normalized !== '') {
                        $raw[] = $normalized;
                    }
                }
            }
        } else {
            $mode = strtolower(trim((string) $this->config->get('glo.official_source.mode', 'fixture')));
            if ($mode !== '') {
                $raw[] = $mode;
            }
        }

        $order = [];

        foreach ($raw as $name) {
            if (isset($this->providers[$name])) {
                $order[] = $name;
            }
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function terminal(
        string $provider,
        ?string $endpoint,
        ?string $drawNumber,
        string $reason,
        string $providerStatus,
    ): array {
        return [
            'status' => 'failed',
            'provider_status' => $providerStatus,
            'provider' => $provider,
            'endpoint' => $endpoint,
            'draw_number' => $drawNumber,
            'first_prize' => null,
            'bottom_two' => null,
            'second_prize' => [],
            'third_prize' => [],
            'n3' => [],
            'tiers' => [],
            'failure_reason' => $reason,
            'fingerprint' => null,
            'attempts' => $this->recorder->attempts(),
        ];
    }
}
