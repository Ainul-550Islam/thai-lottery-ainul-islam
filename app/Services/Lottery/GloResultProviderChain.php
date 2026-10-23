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

    /**
     * @var list<array{source: string, outcome: string, reason: ?string}>
     */
    private array $lastAttempts = [];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly CircuitBreaker $breaker,
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
        return $this->lastAttempts;
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
        $this->lastAttempts = [];
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

        foreach ($order as $name) {
            $provider = $this->providers[$name];

            if ($name === 'fixture' && $hadUpstreamFailure && ! $allowFixtureFallthrough) {
                break;
            }

            if (! $provider->isConfigured()) {
                $this->lastAttempts[] = [
                    'source' => $name,
                    'outcome' => 'skipped',
                    'reason' => 'not_configured',
                ];
                $failureParts[] = "{$name} (not_configured)";
                $sawNotConfigured = true;

                continue;
            }

            if (! $this->breaker->allows($name, $policy)) {
                $state = $this->breaker->describe($name, $policy);
                $reason = sprintf('circuit open (retry after %ds)', $state['retry_after_seconds']);

                $this->lastAttempts[] = [
                    'source' => $name,
                    'outcome' => 'skipped',
                    'reason' => $reason,
                ];
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

                $this->lastAttempts[] = [
                    'source' => $name,
                    'outcome' => 'failed',
                    'reason' => $reason,
                ];
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

                    $this->lastAttempts[] = [
                        'source' => $name,
                        'outcome' => 'failed',
                        'reason' => $reason,
                    ];
                    $failureParts[] = "{$name} (failed: {$reason})";
                    $hadUpstreamFailure = true;
                    $sawNonNotConfigured = true;

                    continue;
                }

                $this->breaker->recordSuccess($name, $policy);

                $this->lastAttempts[] = [
                    'source' => $name,
                    'outcome' => 'imported',
                    'reason' => null,
                ];

                $payload['provider'] = $lastProvider;
                $payload['attempts'] = $this->lastAttempts;

                return $payload;
            }

            $reason = isset($payload['failure_reason']) && (string) $payload['failure_reason'] !== ''
                ? (string) $payload['failure_reason']
                : $status;
            $isOutage = (bool) ($payload['outage'] ?? false);

            if ($status === 'not_configured' && ! $isOutage) {
                $this->lastAttempts[] = [
                    'source' => $name,
                    'outcome' => 'not_configured',
                    'reason' => $reason,
                ];
                $failureParts[] = "{$name} (not_configured: {$reason})";
                $sawNotConfigured = true;

                continue;
            }

            $this->breaker->recordFailure($name, $policy);

            $this->lastAttempts[] = [
                'source' => $name,
                'outcome' => 'failed',
                'reason' => $reason,
            ];
            $failureParts[] = "{$name} ({$status}: {$reason})";
            $hadUpstreamFailure = true;

            if ($status === 'not_configured') {
                $sawNotConfigured = true;
            } else {
                $sawNonNotConfigured = true;
            }
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
            'attempts' => $this->lastAttempts,
        ];
    }
}
