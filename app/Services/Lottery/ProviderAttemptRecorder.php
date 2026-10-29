<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\AuditLog;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * Structured, non-sensitive operational record for a GLO provider attempt.
 *
 * The recorder answers two questions about a ladder walk: what happened on
 * each rung, and in what order. It keeps that walk in memory (so the answer can
 * be returned with the payload that ended it) and mirrors it to the log (so an
 * operator can see a dead upstream before the public surface does).
 *
 * Provider response bodies, credentials, full URLs and raw result numbers are
 * intentionally excluded. The owning import service persists the accepted
 * result/provenance; this recorder explains why a rung was skipped, answered or
 * failed without turning application logs into another copy of payment/result
 * data.
 *
 * The attempt shape is a contract:
 *
 *     ['source' => string, 'outcome' => string, 'reason' => string|null]
 *
 * Callers compare whole arrays, so key order and the presence of `reason` (even
 * when null) are both part of the contract.
 */
final class ProviderAttemptRecorder
{
    /**
     * Human sentences for the machine codes the ladder itself emits.
     *
     * A code that is not in this map is passed through verbatim: an operator
     * reading "some_new_reason" is better served than one reading "unknown".
     *
     * @var array<string, string>
     */
    private const SKIP_REASON_SENTENCES = [
        'circuit_open' => 'source skipped: circuit open, no request was made',
        'not_configured' => 'source skipped: not configured in this deployment',
    ];

    /**
     * @var list<array{source: string, outcome: string, reason: string|null}>
     */
    private array $attempts = [];

    public function skipped(string $source, ?string $drawNumber, string $reason): void
    {
        $this->record($source, $drawNumber, 'skipped', $reason);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function answered(string $source, ?string $drawNumber, array $payload): void
    {
        // The caller imports what it received, so the honest outcome name for an
        // answered rung is `imported` - not `answered`, which describes the
        // transport rather than the result.
        $this->record($source, $drawNumber, 'imported', null, $payload);
    }

    public function failed(string $source, ?string $drawNumber, string $reason): void
    {
        $this->record($source, $drawNumber, 'failed', $reason);
    }

    /**
     * Record an attempt whose outcome the caller has already decided.
     *
     * `not_configured` is a deployment fact rather than a rung outcome, so the
     * ladder needs to be able to say exactly that instead of forcing every
     * non-answering rung into `skipped` or `failed`.
     */
    public function record(
        string $source,
        ?string $drawNumber,
        string $outcome,
        ?string $reason = null,
        array $payload = [],
    ): void {
        $this->attempts[] = [
            'source' => $source,
            'outcome' => $outcome,
            'reason' => $this->reasonFor($outcome, $reason),
        ];

        $this->write($outcome, $source, $drawNumber, $reason, $payload);
    }

    /**
     * @return list<array{source: string, outcome: string, reason: string|null}>
     */
    public function attempts(): array
    {
        return $this->attempts;
    }

    public function count(): int
    {
        return count($this->attempts);
    }

    /**
     * @return array{source: string, outcome: string, reason: string|null}|null
     */
    public function last(): ?array
    {
        return $this->attempts === [] ? null : $this->attempts[count($this->attempts) - 1];
    }

    /**
     * The ladder is a long-lived service in the container: one draw's walk must
     * never be reported as the next draw's, so every fetch starts clean.
     */
    public function reset(): void
    {
        $this->attempts = [];
    }

    private function reasonFor(string $outcome, ?string $reason): ?string
    {
        if ($outcome === 'imported') {
            return null;
        }

        $reason = $reason === null ? '' : trim($reason);

        if ($reason === '') {
            return $outcome === 'failed' ? 'failed without a reason' : null;
        }

        $sentence = self::SKIP_REASON_SENTENCES[$reason] ?? $reason;

        return mb_substr($this->redact($sentence), 0, 500);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(string $outcome, string $source, ?string $drawNumber, ?string $reason, array $payload): void
    {
        // Logging is telemetry, never a precondition for a result. A recorder
        // that throws because a logger is missing would fail a fetch that
        // actually succeeded, so an unavailable logger degrades to memory only.
        if (! $this->loggingAvailable()) {
            return;
        }

        try {
            $context = [
                AuditLog::ANCHOR_KEY => 'glo-attempt:'.$source.':'.($drawNumber ?? 'none'),
                'source' => $source,
                'draw_number' => $drawNumber,
                'outcome' => $outcome,
                'reason' => $reason === null ? null : mb_substr($this->redact($reason), 0, 500),
            ];

            if (isset($payload['fingerprint']) && is_string($payload['fingerprint'])) {
                $context['fingerprint'] = $payload['fingerprint'];
            }

            $level = match ($outcome) {
                'failed' => 'warning',
                'skipped', 'not_configured' => 'notice',
                default => 'info',
            };

            logger()->log($level, 'glo.provider_attempt.'.$outcome, $context);
        } catch (Throwable) {
            // Telemetry failure must not change the outcome of a fetch.
        }
    }

    private function loggingAvailable(): bool
    {
        try {
            if (! Facade::getFacadeApplication() instanceof Container) {
                return false;
            }

            return Facade::getFacadeApplication()->bound('log');
        } catch (Throwable) {
            return false;
        }
    }

    private function redact(string $text): string
    {
        $redacted = preg_replace(
            '/([?&](?:authorization|access[_-]?token|token|api[_-]?key|client[_-]?secret|secret|password|signature)=)[^&#\s]*/i',
            '$1[redacted]',
            $text,
        ) ?? $text;

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
