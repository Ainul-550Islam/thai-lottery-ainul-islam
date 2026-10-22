<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;

class ProviderAttemptRecorder
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
            : 'answered';

        $this->attempts[] = [
            'source' => $source,
            'outcome' => $outcome,
            'reason' => null,
        ];

        $this->safeLog('info', 'glo.provider_attempt.answered', $this->context($source, $drawNumber, null));
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

    private function redactReason(string $reason): string
    {
        $clean = preg_replace('/([?&](?:api[_-]?key|token|secret|password|sig|signature|key)=)[^&\s]+/i', '$1[REDACTED]', $reason) ?? $reason;
        $clean = preg_replace('/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer [REDACTED]', $clean) ?? $clean;

        return trim($clean);
    }

    /**
     * @return array{source: string, draw_number: string|null, reason: string|null}
     */
    private function context(string $source, ?string $drawNumber, ?string $reason): array
    {
        return [
            'source' => $source,
            'draw_number' => $drawNumber,
            'reason' => $reason,
        ];
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
