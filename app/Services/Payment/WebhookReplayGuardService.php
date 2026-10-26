<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\WebhookReplayGuard;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Durable, database-backed replay observations for accepted webhook deliveries.
 *
 * A row is written only after the provider-specific verifier has accepted the
 * signature AND the controller has returned a successful response. This avoids
 * consuming a provider retry when the first attempt was refused or failed.
 * Replays are matched on both the gateway-scoped signature nonce and the raw
 * request-body hash; a signature alone is never enough to suppress a different
 * body.
 */
final class WebhookReplayGuardService
{
    /**
     * Find an already accepted, byte-identical signed delivery and count the
     * replay atomically. The stored row itself is proof that this exact
     * gateway/signature/body combination passed the provider driver previously.
     */
    public function recordReplayIfAccepted(Request $request, string $gateway): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $signature = $this->signature($request, $gateway);
        $body = $request->getContent();

        if ($signature === '' || $body === '') {
            return false;
        }

        $nonce = $this->nonce($request, $gateway, $signature);
        $payloadHash = hash('sha256', $body);

        $guard = WebhookReplayGuard::query()
            ->live()
            ->where('gateway', $gateway)
            ->where('nonce', $nonce)
            ->where('payload_hash', $payloadHash)
            ->when(
                $this->bankTransactionId($request, $gateway) !== '',
                fn ($query) => $query->where('signature', hash('sha256', $signature)),
            )
            ->first();

        if (! $guard instanceof WebhookReplayGuard) {
            return false;
        }

        $now = now();
        $updated = WebhookReplayGuard::query()
            ->live()
            ->whereKey((int) $guard->getKey())
            ->update([
                'deliveries' => DB::raw('deliveries + 1'),
                'last_seen_at' => $now,
                'last_ip' => $request->ip(),
            ]);

        if ($updated !== 1) {
            // The retention boundary crossed between lookup and update. Let the
            // provider driver evaluate the request normally; it may be valid,
            // stale, or a secret-rotation retry.
            return false;
        }

        Log::warning('webhook.replay_rejected', [
            'gateway' => $gateway,
            'nonce' => $nonce,
            'payload_hash' => $payloadHash,
            'deliveries' => (int) $guard->deliveries + 1,
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);

        return true;
    }

    /**
     * Persist an accepted delivery. A unique-index conflict with the same
     * payload increments the observation count. A conflict with a different
     * payload is treated as an integrity error and is never overwritten.
     */
    public function rememberAccepted(Request $request, string $gateway): void
    {
        if (! $this->enabled()) {
            return;
        }

        $signature = $this->signature($request, $gateway);
        $body = $request->getContent();

        if ($signature === '' || $body === '') {
            return;
        }

        $nonce = $this->nonce($request, $gateway, $signature);
        $payloadHash = hash('sha256', $body);
        $now = now();
        $expiresAt = $now->copy()->addDays($this->retentionDays());
        $ip = $request->ip();

        try {
            $inserted = DB::table('webhook_replay_guards')->insertOrIgnore([
                'gateway' => $gateway,
                'nonce' => $nonce,
                'signature' => hash('sha256', $signature),
                'payload_hash' => $payloadHash,
                'deliveries' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => $expiresAt,
                'first_ip' => $ip,
                'last_ip' => $ip,
            ]);
        } catch (QueryException $exception) {
            Log::critical('webhook.replay_guard_write_failed', [
                'gateway' => $gateway,
                'nonce' => $nonce,
                'payload_hash' => $payloadHash,
                'error_class' => $exception::class,
            ]);

            throw $exception;
        }

        if ($inserted === 1) {
            $rotated = WebhookReplayGuard::query()
                ->live()
                ->where('gateway', $gateway)
                ->where('payload_hash', $payloadHash)
                ->where('nonce', '!=', $nonce)
                ->exists();

            if ($rotated) {
                Log::warning('webhook.replay_signature_rotated', [
                    'gateway' => $gateway,
                    'payload_hash' => $payloadHash,
                    'nonce' => $nonce,
                    'ip' => $ip,
                    'path' => $request->path(),
                ]);
            }

            return;
        }

        $updated = DB::table('webhook_replay_guards')
            ->where('gateway', $gateway)
            ->where('nonce', $nonce)
            ->where('payload_hash', $payloadHash)
            ->update([
                'signature' => hash('sha256', $signature),
                'deliveries' => DB::raw('deliveries + 1'),
                'last_seen_at' => $now,
                'expires_at' => $expiresAt,
                'last_ip' => $ip,
            ]);

        if ($updated !== 1) {
            Log::critical('webhook.replay_guard_identity_conflict', [
                'gateway' => $gateway,
                'nonce' => $nonce,
                'payload_hash' => $payloadHash,
                'ip' => $ip,
                'path' => $request->path(),
            ]);

            if ($this->bankTransactionId($request, $gateway) !== '') {
                // The bank matcher has already applied its own bank_txn_id
                // receipt and returned a verified review/idempotent result.
                // Preserve the original replay evidence without overwriting it
                // or converting that money-safe response into a server error.
                return;
            }

            throw new LogicException('A webhook signature nonce is already bound to a different payload.');
        }
    }

    /**
     * Resolve the exact signature header accepted by the provider driver.
     * The order mirrors each driver's fallback order, preventing a secondary
     * header from shadowing the value that was actually verified.
     */
    private function signature(Request $request, string $gateway): string
    {
        if ($request->is('api/v1/payments/webhook/v2/*')) {
            foreach (['X-Signature', 'X-Webhook-Signature', 'Stripe-Signature'] as $header) {
                $value = $request->header($header);

                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }

            return '';
        }

        $headers = match (strtolower($gateway)) {
            'stripe' => ['Stripe-Signature'],
            'bkash' => ['X-Bkash-Signature', 'X-Signature'],
            'nagad' => ['X-KM-Signature', 'X-Nagad-Signature', 'X-Signature'],
            'crypto' => ['X-Crypto-Signature', 'X-Signature'],
            'kbank' => [config('payment.gateways.kbank.signature_header', 'X-KBANK-Signature'), 'X-KBANK-Signature'],
            'scb' => [config('payment.gateways.scb.signature_header', 'X-SCB-Signature'), 'X-SCB-Signature'],
            default => array_values(array_filter([
                config("payment.gateways.{$gateway}.signature_header"),
                'X-Signature',
                'X-Webhook-Signature',
            ], static fn (mixed $header): bool => is_string($header) && $header !== '')),
        };

        foreach ($headers as $header) {
            $value = $request->header($header);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function nonce(Request $request, string $gateway, string $signature): string
    {
        $normalizedGateway = strtolower($gateway);
        $bankTransactionId = $this->bankTransactionId($request, $normalizedGateway);

        return $bankTransactionId !== ''
            ? hash('sha256', $normalizedGateway.'|bank_txn_id|'.$bankTransactionId)
            : hash('sha256', $normalizedGateway.'|'.$signature);
    }

    private function bankTransactionId(Request $request, string $gateway): string
    {
        if (! in_array(strtolower($gateway), ['kbank', 'scb'], true)) {
            return '';
        }

        $payload = $request->json()->all();
        foreach (['bank_txn_id', 'tx_id', 'transaction_no', 'transactionId', 'transaction_id'] as $key) {
            $value = $payload[$key] ?? null;
            if ((is_string($value) || is_int($value)) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function enabled(): bool
    {
        return (bool) config('payment.webhook.replay_protection', true);
    }

    private function retentionDays(): int
    {
        return max(1, min(3650, (int) config('payment.webhook.replay_guard_retention_days', 30)));
    }
}
