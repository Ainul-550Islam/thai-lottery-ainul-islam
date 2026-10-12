<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Services\Lottery\Support\BreakerPolicy;
use App\Services\Lottery\Support\CircuitBreaker;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Ordered, fail-closed GLO result-source chain.
 *
 * The source that actually returns a usable, fingerprinted result owns the
 * provider label in the payload and provenance record. A fixture may be the
 * explicitly configured local source, but it is never an implicit production
 * fallback after an upstream failure.
 */
final class GloResultProviderChain implements GloResultProvider
{
    /** @var list<array{source: string, outcome: string, reason: string|null}> */
    private array $attempts = [];

    public function __construct(
        private readonly GloOfficialResultProvider $officialProvider,
        private readonly GloFixtureResultProvider $fixtureProvider,
        private readonly CircuitBreaker $breaker,
        private readonly ProviderAttemptRecorder $recorder,
        private readonly ConfigRepository $config,
    ) {}

    public function name(): string
    {
        return 'chain';
    }

    public function isConfigured(): bool
    {
        foreach ($this->configuredSources() as $source) {
            if ($source->isConfigured()) {
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
        return $this->attempts;
    }

    /**
     * Fetch from sources in configured authority order.
     *
     * A source is eligible for fallback after an upstream failure only when
     * the next source is not a fixture, or GLO_FALL_THROUGH_TO_FIXTURE was
     * explicitly enabled. A source that is not configured is not an upstream
     * failure and does not trip the circuit breaker.
     *
     * @return array<string, mixed>
     */
    public function fetch(?string $drawNumber = null): array
    {
        $this->attempts = [];
        $sources = $this->configuredSources();

        if ($sources === []) {
            return $this->terminal('No result source is configured on the ladder.', $drawNumber, 'not_configured');
        }

        $policy = BreakerPolicy::fromConfig($this->config);
        $failures = [];
        $upstreamFailed = false;
        $allowFixtureFallback = (bool) $this->config->get('glo.sources.fall_through_to_fixture', false);

        foreach ($sources as $source) {
            $sourceName = $source->name();
            $isFixtureFallback = $sourceName === 'fixture' && $upstreamFailed && ! $allowFixtureFallback;

            if ($isFixtureFallback) {
                $reason = 'fixture fallback is disabled';
                $this->appendAttempt($sourceName, 'skipped', $reason);
                $this->recorder->skipped($sourceName, $drawNumber, 'fixture_fallback_disabled');
                $failures[] = $sourceName.': '.$reason;

                continue;
            }

            if (! $source->isConfigured()) {
                $reason = 'source is not configured';
                $this->appendAttempt($sourceName, 'skipped', $reason);
                $this->recorder->skipped($sourceName, $drawNumber, 'not_configured');
                $failures[] = $sourceName.': '.$reason;

                continue;
            }

            if (! $this->breaker->allows($sourceName, $policy)) {
                $reason = 'circuit open';
                $this->appendAttempt($sourceName, 'skipped', $reason);
                $this->recorder->skipped($sourceName, $drawNumber, 'circuit_open');
                $upstreamFailed = true;
                $failures[] = $sourceName.': '.$reason;

                continue;
            }

            try {
                $payload = $source->fetch($drawNumber);
            } catch (Throwable $exception) {
                $payload = [
                    'status' => 'failed',
                    'provider' => $sourceName,
                    'endpoint' => null,
                    'draw_number' => $drawNumber,
                    'first_prize' => null,
                    'second_prize' => [],
                    'third_prize' => [],
                    'n3' => [],
                    'tiers' => [],
                    'failure_reason' => get_class($exception).' while fetching configured source',
                    'fingerprint' => null,
                ];
            }

            $statusValue = $payload['status'] ?? null;
            $status = is_string($statusValue) ? $statusValue : 'failed';

            if ($status === 'imported' && $this->isUsable($payload, $drawNumber)) {
                $this->breaker->recordSuccess($sourceName, $policy);
                // The selected source object, not an upstream payload field,
                // owns the provenance label.
                $payload['provider'] = $sourceName;
                $this->appendAttempt($sourceName, 'imported', null);
                $this->recorder->answered($sourceName, $drawNumber, $payload);
                $payload['ladder'] = $this->attempts;

                return $payload;
            }

            $reasonValue = $payload['failure_reason'] ?? null;
            $reason = $this->safeReason(is_string($reasonValue) ? trim($reasonValue) : $status);

            if ($status === 'imported') {
                $reason = 'imported payload has an invalid draw identity, six-digit first prize, or SHA-256 fingerprint';
            }

            if ($status === 'not_configured') {
                $reason = 'not_configured'.($reason !== '' ? ': '.$reason : '');
            }

            $failures[] = $sourceName.': '.($reason !== '' ? $reason : $status);

            if ($status === 'not_configured') {
                // This is a deployment/configuration fact, not evidence that an
                // upstream is unhealthy. Do not increment the breaker.
                $this->appendAttempt($sourceName, 'skipped', $reason !== '' ? $reason : 'not_configured');
                $this->recorder->skipped($sourceName, $drawNumber, 'not_configured');

                continue;
            }

            // An `imported` response without a valid first prize/fingerprint is
            // also a failed source response. It must never be accepted or used to
            // reset the breaker.
            $upstreamFailed = true;
            $this->breaker->recordFailure($sourceName, $policy, $reason);
            $this->appendAttempt($sourceName, 'failed', $reason !== '' ? $reason : $status);
            $this->recorder->failed($sourceName, $drawNumber, $reason !== '' ? $reason : $status);
        }

        if ($upstreamFailed) {
            $reason = 'Every result source failed; '.implode('; ', $failures);
            $terminalStatus = 'failed';
        } elseif ($failures !== []) {
            $reason = 'No configured result source answered; '.implode('; ', $failures);
            $terminalStatus = 'not_configured';
        } else {
            $reason = 'No configured result source answered.';
            $terminalStatus = 'not_configured';
        }

        return $this->terminal($reason, $drawNumber, $terminalStatus);
    }

    /**
     * @return list<GloResultProvider>
     */
    private function configuredSources(): array
    {
        $priority = $this->config->get('glo.sources.priority', []);
        $priority = is_array($priority) ? array_values($priority) : [];

        if ($priority === []) {
            $mode = (string) $this->config->get('glo.official_source.mode', 'fixture');
            $priority = [$mode === 'official' ? 'official' : 'fixture'];
        }

        $available = [
            'official' => $this->officialProvider,
            'fixture' => $this->fixtureProvider,
        ];
        $sources = [];
        $seen = [];

        foreach ($priority as $entry) {
            $name = strtolower(trim((string) $entry));

            if ($name === '' || isset($seen[$name]) || ! isset($available[$name])) {
                continue;
            }

            $seen[$name] = true;
            $sources[] = $available[$name];
        }

        return $sources;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isUsable(array $payload, ?string $expectedDrawNumber): bool
    {
        $fingerprintValue = $payload['fingerprint'] ?? null;
        $firstPrizeValue = $payload['first_prize'] ?? null;
        $returnedDrawValue = $payload['draw_number'] ?? null;

        if (! is_string($fingerprintValue) || ! is_string($firstPrizeValue)) {
            return false;
        }

        $fingerprint = trim($fingerprintValue);
        $firstPrize = trim($firstPrizeValue);

        if ($expectedDrawNumber !== null
            && (! is_string($returnedDrawValue) || $returnedDrawValue !== $expectedDrawNumber)) {
            return false;
        }

        if ($expectedDrawNumber === null && $returnedDrawValue !== null && ! is_string($returnedDrawValue)) {
            return false;
        }

        return preg_match('/\A[a-f0-9]{64}\z/iD', $fingerprint) === 1
            && preg_match('/\A\d{6}\z/D', $firstPrize) === 1;
    }

    private function safeReason(string $reason): string
    {
        $redacted = preg_replace(
            '/([?&](?:authorization|access[_-]?token|token|api[_-]?key|client[_-]?secret|secret|password|signature)=)[^&#\\s]*/i',
            '$1[redacted]',
            $reason,
        ) ?? $reason;

        $redacted = preg_replace(
            '/\bauthorization\s*[:=]\s*(?:bearer\s+)?[^\s,;&]+/i',
            'authorization=[redacted]',
            $redacted,
        ) ?? $redacted;

        return preg_replace(
            '/\b(access[_-]?token|token|api[_-]?key|client[_-]?secret|secret|password|signature)\s*[:=]\s*[^\s,;&]+/i',
            '$1=[redacted]',
            $redacted,
        ) ?? $redacted;
    }

    private function appendAttempt(string $source, string $outcome, ?string $reason): void
    {
        $this->attempts[] = [
            'source' => $source,
            'outcome' => $outcome,
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function terminal(string $reason, ?string $drawNumber, string $status): array
    {
        return [
            'status' => $status,
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
            'ladder' => $this->attempts,
        ];
    }
}
