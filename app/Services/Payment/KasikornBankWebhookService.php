<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\AuditAction;
use App\Enums\PromptPayDepositMatchResult;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class KasikornBankWebhookService
{
    public function __construct(private readonly PromptPayDepositMatcher $matcher) {}

    /** @param array<string, mixed> $payload */
    public function processWebhook(array $payload, string $signature, string $clientIp, ?string $timestamp = null): array
    {
        $this->verify($payload, $signature, $timestamp, $clientIp);

        $eventType = strtolower(trim($this->scalar($payload['event_type'] ?? $payload['eventType'] ?? $payload['type'] ?? '')));
        if (! in_array($eventType, ['credit', 'payment.credit', 'payment.completed', 'deposit.completed', 'payment_success', 'promptpay.credit'], true)) {
            return $this->result(PromptPayDepositMatchResult::Unmatched, '', 'ignored');
        }

        $bankTransactionId = $this->firstScalar($payload, ['bank_txn_id', 'tx_id', 'transaction_no', 'transactionId']);
        $reference = $this->firstScalar($payload, ['bill_reference', 'reference', 'reference_1', 'ref1', 'billPaymentRef1']);
        $amount = $this->firstMoney($payload, ['amount', 'net_amount', 'paid_amount']);
        $currency = $this->firstScalar($payload, ['currency', 'currency_code']);
        $matched = $this->matcher->match('kbank', $reference, $amount, $currency, $bankTransactionId);

        return $this->result($matched, $bankTransactionId);
    }

    public function verifyRequest(Request $request): bool
    {
        $payload = $request->json()->all();
        if (! is_array($payload)) {
            return false;
        }

        try {
            $this->verify(
                $payload,
                (string) $request->header('X-KBANK-Signature', ''),
                $request->header('X-KBANK-Timestamp') ?? $request->header('X-Timestamp'),
                (string) $request->ip(),
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $payload */
    private function verify(array $payload, string $signature, ?string $timestamp, string $clientIp): void
    {
        $secret = trim((string) config('services.kbank.webhook_secret', ''));
        if ($secret === '') {
            Log::critical('KBank webhook refused: webhook secret is not configured.', ['client_ip' => $clientIp]);
            $this->auditRejected($payload, 'missing_webhook_secret', $clientIp);
            throw new RuntimeException('KBank webhook lane is not configured; notification refused.');
        }

        $rawContent = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $payloadTimestamp = $this->firstScalar($payload, ['timestamp', 'created', 'created_at']);
        $headerTimestamp = trim((string) $timestamp);
        $signedTimestamp = $payloadTimestamp !== '' ? $payloadTimestamp : $headerTimestamp;
        // A body timestamp is already covered by the body HMAC and takes
        // precedence over a conflicting header. For header-only providers the
        // signed content is "timestamp.raw-body"; an unsigned freshness header
        // can never make an old body acceptable.
        $signedContent = $payloadTimestamp === '' && $headerTimestamp !== ''
            ? $headerTimestamp.'.'.$rawContent
            : $rawContent;
        $provided = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        $expected = hash_hmac('sha256', $signedContent, $secret);
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            $this->auditRejected($payload, 'invalid_signature', $clientIp);
            throw new InvalidArgumentException('Invalid KBank signature.');
        }

        $eventTime = $this->timestamp($signedTimestamp);
        $tolerance = max(1, (int) config('payment.webhook.max_age_seconds', 300));
        if ($eventTime === null || abs(time() - $eventTime) > $tolerance) {
            $this->auditRejected($payload, 'invalid_or_expired_timestamp', $clientIp);
            throw new InvalidArgumentException('Invalid or expired KBank webhook timestamp.');
        }
    }

    /** @param array<string, mixed> $payload @param list<string> $keys */
    private function firstScalar(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return $this->scalar($payload[$key]);
            }
        }

        return '';
    }

    /** @param array<string, mixed> $payload @param list<string> $keys */
    private function firstMoney(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                $value = $payload[$key];

                return is_string($value) || is_int($value) ? (string) $value : '';
            }
        }

        return '';
    }

    private function scalar(mixed $value): string
    {
        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }

    private function timestamp(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }

        $parsed = strtotime($value);

        return $parsed === false ? null : $parsed;
    }

    /** @param array<string, mixed> $payload */
    private function auditRejected(array $payload, string $reason, string $clientIp): void
    {
        $audit = new AuditLog;
        $audit->fill([
            'user_id' => null,
            'action' => AuditAction::SecurityAlert,
            'risk_level' => RiskLevel::High,
            'auditable_type' => null,
            'auditable_id' => null,
            'description' => 'A bank deposit notification was rejected before deposit matching.',
            'ip_address' => $clientIp,
            'metadata' => [
                'bank' => 'kbank',
                'reason' => $reason,
                'payload_sha256' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            ],
        ]);
        $audit->save();
    }

    private function result(PromptPayDepositMatchResult $result, string $transactionId, ?string $status = null): array
    {
        $value = $status ?? $result->value;

        return [
            'success' => true,
            'status' => strtoupper($value),
            'result' => $value,
            'transaction_id' => $transactionId,
            'trans_ref' => $transactionId,
            'credited' => $result === PromptPayDepositMatchResult::Credited,
        ];
    }
}
