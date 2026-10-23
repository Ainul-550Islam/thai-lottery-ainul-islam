<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;

/**
 * Structured, non-sensitive operational record for a GLO provider attempt.
 */
final class ProviderAttemptRecorder
{
    /**
     * @var list<array{source: string, outcome: string, reason: string|null}>
     */
    private array $attempts = [];

    /**
     * @param  array<string, mixed>  $result
     */
    public function answered(string $source, ?string $drawNumber, array $result = []): void
    {
        $outcome = isset($result['status']) && is_string($result['status']) && $result['status'] !== ''
            ? $result['status']
            : 'imported';

        $this->attempts[] = [
            'source' => $source,
            'outcome' => $outcome,
            'reason' => null,
        ];

        $this->safeLog('info', 'glo.provider_attempt.answered', [
            'source' => $source,
            'draw_number' => $drawNumber,
            'fingerprint' => isset($result['fingerprint']) ? (string) $result['fingerprint'] : null,
        ]);
    }

    public function skipped(string $source, ?string $drawNumber, string $code): void
    {
        $reason = match ($code) {
            'circuit_open' => 'Provider skipped because circuit open.',
            'not_configured' => 'Provider is not configured for this environment.',
            default => $code,
        };

        $this->attempts[] = [
            'source' => $source,
            'outcome' => 'skipped',
            'reason' => $reason,
        ];

        $this->safeLog('notice', 'glo.provider_attempt.skipped', $this->context($source, $drawNumber, $reason));
    }

    public function failed(string $source, ?string $drawNumber, string $reason): void
    {
        $sanitized = trim($reason) === ''
            ? 'failed without a reason'
            : mb_substr($this->redactReason($reason), 0, 500);

        $this->attempts[] = [
            'source' => $source,
            'outcome' => 'failed',
            'reason' => $sanitized,
        ];

        $this->safeLog('warning', 'glo.provider_attempt.failed', $this->context($source, $drawNumber, $sanitized));
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
        if ($this->attempts === []) {
            return null;
        }

        return $this->attempts[array_key_last($this->attempts)];
    }

    public function reset(): void
    {
        $this->attempts = [];
    }

    /**
     * @return array{source: string, draw_number: string|null, reason: string|null}
     */
    private function context(string $source, ?string $drawNumber, ?string $reason): array
    {
        return [
            AuditLog::ANCHOR_KEY => 'glo-attempt:'.$source.':'.($drawNumber ?? 'none'),
            'source' => $source,
            'draw_number' => $drawNumber,
            'reason' => $reason === null ? null : mb_substr($this->redactReason($reason), 0, 500),
        ];
    }

    private function redactReason(string $reason): string
    {
        $redacted = preg_replace(
            '/([?&](?:authorization|access[_-]?token|token|api[_-]?key|client[_-]?secret|secret|password|sig|signature|key)=)[^&#\\s]*/i',
            '$1[redacted]',
            $reason,
        ) ?? $reason;

        $redacted = preg_replace(
            '/\bauthorization\s*[:=]\s*(?:bearer\s+)?[^\s,;&]+/i',
            'authorization=[redacted]',
            $redacted,
        ) ?? $redacted;

        $redacted = preg_replace(
            '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i',
            'Bearer [redacted]',
            $redacted,
        ) ?? $redacted;

        return preg_replace(
            '/\b(access[_-]?token|token|api[_-]?key|client[_-]?secret|secret|password|signature)\s*[:=]\s*[^\s,;&]+/i',
            '$1=[redacted]',
            $redacted,
        ) ?? $redacted;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function safeLog(string $level, string $message, array $context): void
    {
        $app = Facade::getFacadeApplication();
        if ($app === null || ! $app->bound('log')) {
            return;
        }

        Log::log($level, $message, $context);
    }
}
