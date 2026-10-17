<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use Psr\Log\LoggerInterface;

/**
 * The result ladder's walk log.
 *
 * Every rung the chain touches — answered, failed, or skipped before it was
 * asked — is recorded here in walk order, in the exact entry shape the
 * ladder's contract pins: ['source' => ..., 'outcome' => ..., 'reason' => ...].
 * `GloResultProviderChain::attempts()` reads this log back, so one draw's
 * attempts are never reported as the next one's (reset() between walks).
 *
 * The recorder also emits a guarded operational log line per attempt
 * (credential-redacted, length-bounded, no payloads). Logging is strictly
 * optional: unit tests instantiate this class with no bootstrapped
 * application, so a missing logger or a missing 'log' binding is swallowed
 * — the in-memory walk is the contract, the log is observability.
 */
final class ProviderAttemptRecorder
{
    /** @var list<array{source: string, outcome: string, reason: string|null}> */
    private array $entries = [];

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function skipped(string $source, ?string $drawNumber, string $reason): void
    {
        $sentence = match ($reason) {
            // The exact sentences the ladder's contract asserts on: the skip
            // reason carries the machine code's human form
            // ("circuit open" / "not configured").
            'circuit_open' => 'circuit open',
            'not_configured' => 'not configured',
            default => $reason,
        };

        $this->entries[] = ['source' => $source, 'outcome' => 'skipped', 'reason' => $sentence];

        $this->log('notice', 'glo.provider_attempt.skipped', $source, $drawNumber, $sentence);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function answered(string $source, ?string $drawNumber, array $payload): void
    {
        $this->entries[] = ['source' => $source, 'outcome' => 'imported', 'reason' => null];

        $this->log('info', 'glo.provider_attempt.answered', $source, $drawNumber, null);
    }

    public function failed(string $source, ?string $drawNumber, string $reason): void
    {
        $reason = trim($reason) === '' ? 'failed without a reason' : $reason;

        $this->entries[] = ['source' => $source, 'outcome' => 'failed', 'reason' => $reason];

        $this->log('warning', 'glo.provider_attempt.failed', $source, $drawNumber, $reason);
    }

    /**
     * @return list<array{source: string, outcome: string, reason: string|null}>
     */
    public function attempts(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * @return array{source: string, outcome: string, reason: string|null}|null
     */
    public function last(): ?array
    {
        return $this->entries === [] ? null : $this->entries[array_key_last($this->entries)];
    }

    public function reset(): void
    {
        $this->entries = [];
    }

    private function log(string $level, string $message, string $source, ?string $drawNumber, ?string $reason): void
    {
        try {
            $logger = $this->logger;

            if ($logger === null && function_exists('app')) {
                $app = app();

                if (is_object($app) && method_exists($app, 'bound') && $app->bound('log')) {
                    $candidate = $app->make('log');

                    if ($candidate instanceof LoggerInterface) {
                        $logger = $candidate;
                    }
                }
            }

            if ($logger === null) {
                return;
            }

            $logger->log($level, $message, [
                'source' => $source,
                'draw_number' => $drawNumber,
                'reason' => $reason === null ? null : mb_substr($this->redactReason($reason), 0, 500),
            ]);
        } catch (\Throwable) {
            // Observability must never break the walk.
        }
    }

    private function redactReason(string $reason): string
    {
        $redacted = preg_replace(
            '/([?&](?:authorization|access[_-]?token|token|api[_-]?key|client[_-]?secret|secret|password|signature)=)[^&#\s]*/i',
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
}
