<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use Illuminate\Support\Facades\Log;

/**
 * Structured, non-sensitive operational record for a GLO provider attempt.
 *
 * Provider response bodies, credentials, full URLs and raw result numbers are
 * intentionally excluded. The owning import service persists the accepted
 * result/provenance; this recorder explains why a rung was skipped, answered or
 * failed without turning application logs into another copy of payment/result
 * data.
 */
final class ProviderAttemptRecorder
{
    public function skipped(string $source, ?string $drawNumber, string $reason): void
    {
        Log::notice('glo.provider_attempt.skipped', $this->context($source, $drawNumber, $reason));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function answered(string $source, ?string $drawNumber, array $payload): void
    {
        Log::info('glo.provider_attempt.answered', [
            'source' => $source,
            'draw_number' => $drawNumber,
            'fingerprint' => isset($payload['fingerprint']) ? (string) $payload['fingerprint'] : null,
        ]);
    }

    public function failed(string $source, ?string $drawNumber, string $reason): void
    {
        Log::warning('glo.provider_attempt.failed', $this->context($source, $drawNumber, $reason));
    }

    /**
     * @return array{source: string, draw_number: string|null, reason: string}
     */
    private function context(string $source, ?string $drawNumber, string $reason): array
    {
        return [
            'source' => $source,
            'draw_number' => $drawNumber,
            'reason' => mb_substr($this->redactReason($reason), 0, 500),
        ];
    }

    private function redactReason(string $reason): string
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
}
