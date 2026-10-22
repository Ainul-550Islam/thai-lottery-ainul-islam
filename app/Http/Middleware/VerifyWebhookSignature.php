<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\WebhookReplayGuard;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Durable replay guard for inbound payment webhooks.
 *
 * ============================================================================
 * WHAT THIS MIDDLEWARE IS — AND WHAT IT DELIBERATELY IS NOT
 * ============================================================================
 * It is NOT a signature verifier, despite the class name and the
 * `webhook.signature` route alias it is registered under. Each provider signs
 * differently: Stripe signs "<timestamp>.<body>" and ships the timestamp INSIDE
 * the `Stripe-Signature` header as `t=…,v1=…`; bKash, Nagad, Crypto and Bank
 * Transfer sign the raw body and carry no timestamp at all. Every one of those
 * already has an authoritative implementation behind
 * `PaymentGatewayInterface::verifyWebhookSignature()`.
 *
 * Re-deriving "valid signature" here would create a SECOND definition of it,
 * and the two would drift — which is exactly how the previous version of this
 * file broke this surface. It assumed one flat scheme for all providers
 * (signature in `signature_header`, timestamp in a separate `X-Timestamp`), so
 * every real delivery was refused 403 "Missing webhook timestamp" before any
 * handler ran: Stripe never sends `X-Timestamp`, and bKash/crypto have no
 * timestamp to send.
 *
 * So this class owns exactly one thing: REPLAY. It claims a nonce in
 * `webhook_replay_guards`, whose UNIQUE (gateway, nonce) index is the actual
 * control, and it enforces a tolerance window ONLY for providers whose protocol
 * carries a timestamp.
 *
 * ============================================================================
 * THE TWO RULES THAT ARE EASY TO GET WRONG
 * ============================================================================
 * 1. A replay is answered 200, not 4xx. The work is already done, and a
 *    provider that receives a 4xx treats the delivery as failed and retries it
 *    forever. The body carries `duplicate: true` so the fact is observable.
 *
 * 2. A delivery we REFUSED must not be remembered as seen. The claim is written
 *    before the handler runs (so two concurrent identical deliveries cannot both
 *    win the insert), and is RELEASED if the handler returns >= 400. Otherwise a
 *    bad signature during a secret rotation, or a 422 for a deposit we had not
 *    written yet, would be claimed; the provider's retry — the very mechanism
 *    that recovers from that failure — would then be answered 200/duplicate and
 *    never reach the driver. The gateway would be satisfied, the work would
 *    never happen, and the player would not be paid.
 *
 * The nonce is sha256(gateway|signature), so the gateway is part of the
 * identity: one provider's signature string cannot occupy another provider's
 * nonce and refuse a legitimate first delivery. `payload_hash` is stored
 * alongside it, which is what makes key ROTATION visible — the same body under a
 * new timestamp produces a new nonce but the same payload hash.
 */
class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next, ?string $gateway = null): Response
    {
        $gateway = trim($gateway ?? (string) $request->route('gateway'));

        if ($gateway === '') {
            return $this->reject('Webhook gateway could not be resolved.', [
                'gateway' => null,
                'severity' => 'critical',
            ]);
        }

        $headerName = (string) config("payment.gateways.{$gateway}.signature_header", 'X-Signature');
        $rawHeader = trim((string) $request->header($headerName, ''));

        // A structural check only. Whether the signature is CORRECT is the
        // driver's call — see the class docblock.
        if ($rawHeader === '') {
            return $this->reject('Missing webhook signature.', [
                'gateway' => $gateway,
                'header' => $headerName,
            ]);
        }

        [$signature, $timestamp] = $this->parseSignatureHeader($rawHeader, $gateway, $request);

        if ($signature === '') {
            return $this->reject('Webhook signature header is malformed.', [
                'gateway' => $gateway,
                'header' => $headerName,
            ]);
        }

        // Only providers whose protocol carries a timestamp get a window. For
        // the rest the nonce is still the control — replay stays refused, the
        // age of the delivery is simply not something the protocol can assert.
        if ($timestamp !== null) {
            $tolerance = (int) config(
                "payment.gateways.{$gateway}.tolerance_seconds",
                config('security.webhook.timestamp_tolerance_seconds', 300),
            );

            if ($tolerance > 0) {
                $drift = abs(time() - $timestamp);

                if ($drift > $tolerance) {
                    return $this->reject('Webhook timestamp is outside the tolerance window.', [
                        'gateway' => $gateway,
                        'drift_seconds' => $drift,
                        'tolerance_seconds' => $tolerance,
                    ]);
                }
            }
        }

        if (! $this->guardEnabled()) {
            // The escape hatch for diagnosing a provider whose retry behaviour
            // collides with the guard. No rows are written and nothing is
            // refused here; the service layer's own event-id idempotency still
            // applies. Turning it off is a deliberate, configured act.
            return $next($request);
        }

        $body = (string) $request->getContent();
        $nonce = hash('sha256', $gateway.'|'.$signature);

        $existing = $this->find($gateway, $nonce);

        if ($existing !== null) {
            return $this->duplicate($existing, $request);
        }

        try {
            $guard = $this->claim($gateway, $nonce, $signature, $body, $request);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent identical delivery. That delivery
            // owns the work; this one is the duplicate.
            $existing = $this->find($gateway, $nonce);

            if ($existing !== null) {
                return $this->duplicate($existing, $request);
            }

            // The row vanished between the violation and the re-read (retention
            // sweep). Fall through and let the handler judge it on its merits
            // rather than inventing a refusal.
            return $next($request);
        }

        $response = $next($request);

        // Rule 2 from the docblock: a refused delivery releases its claim, so
        // the provider's retry is judged on its own merits.
        if ($response->getStatusCode() >= 400) {
            $guard->delete();

            Log::info('Webhook replay guard released its claim after a refused delivery.', [
                'gateway' => $gateway,
                'status' => $response->getStatusCode(),
                'nonce' => $nonce,
            ]);
        }

        return $response;
    }

    /**
     * Extract the signature, and the timestamp when the protocol carries one.
     *
     * Handles both shapes in use on this surface:
     *   Stripe      "t=1712345678,v1=<hex>[,v0=<hex>]"  — composite, timestamp inside
     *   bKash/Nagad/
     *   Crypto/Bank "<hex>" (optionally prefixed)        — timestamp in a separate
     *                                                     header if one is configured
     *
     * @return array{0: string, 1: int|null}
     */
    private function parseSignatureHeader(string $raw, string $gateway, Request $request): array
    {
        $prefix = (string) config("payment.gateways.{$gateway}.signature_prefix", '');
        $value = ($prefix !== '' && str_starts_with($raw, $prefix))
            ? trim(substr($raw, strlen($prefix)))
            : $raw;

        $signature = $value;
        $timestamp = null;

        // Composite header. Stripe may ship several scheme versions at once
        // (v1 and v0) during a key rotation; v1 is the current one.
        if (str_contains($value, 'v1=')) {
            $parts = [];

            foreach (explode(',', $value) as $chunk) {
                $kv = explode('=', trim($chunk), 2);

                if (count($kv) === 2 && $kv[0] !== '') {
                    $parts[$kv[0]] = trim($kv[1]);
                }
            }

            $signature = $parts['v1'] ?? '';

            if (isset($parts['t']) && ctype_digit($parts['t'])) {
                $timestamp = (int) $parts['t'];
            }
        }

        if ($timestamp === null) {
            $timestampHeader = (string) config("payment.gateways.{$gateway}.timestamp_header", 'X-Timestamp');
            $candidate = trim((string) $request->header($timestampHeader, ''));

            if ($candidate !== '' && ctype_digit($candidate)) {
                $timestamp = (int) $candidate;
            }
        }

        return [$signature, $timestamp];
    }

    private function guardEnabled(): bool
    {
        return (bool) config('payment.webhook.replay_protection', true)
            && (bool) config('security.webhook.replay_protection', true);
    }

    /**
     * @return WebhookReplayGuard|null
     */
    private function find(string $gateway, string $nonce): ?WebhookReplayGuard
    {
        return WebhookReplayGuard::query()
            ->where('gateway', $gateway)
            ->where('nonce', $nonce)
            ->first();
    }

    private function claim(
        string $gateway,
        string $nonce,
        string $signature,
        string $body,
        Request $request,
    ): WebhookReplayGuard {
        $now = now();
        $retentionDays = (int) config('payment.webhook.replay_guard_retention_days', 30);

        return WebhookReplayGuard::query()->create([
            'gateway' => $gateway,
            'nonce' => $nonce,
            'signature' => mb_substr($signature, 0, 255),
            'payload_hash' => hash('sha256', $body),
            'deliveries' => 1,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            // The horizon must be in the FUTURE, or the retention sweep would
            // delete the guard on its next run and re-open the replay window.
            'expires_at' => $retentionDays > 0 ? $now->copy()->addDays($retentionDays) : null,
            'first_ip' => $request->ip(),
            'last_ip' => $request->ip(),
        ]);
    }

    /**
     * Answer a repeat delivery 200. `deliveries` is mutable by design: a
     * provider retrying a webhook it believes failed is normal behaviour, not an
     * attack. What matters is that the WORK happens once.
     */
    private function duplicate(WebhookReplayGuard $guard, Request $request): JsonResponse
    {
        $guard->forceFill([
            'deliveries' => $guard->deliveries + 1,
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
        ])->save();

        Log::warning('Webhook replay refused by the durable guard.', [
            'gateway' => $guard->gateway,
            'nonce' => $guard->nonce,
            'deliveries' => $guard->deliveries,
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);

        return new JsonResponse([
            'success' => true,
            'duplicate' => true,
            'message' => 'This webhook has already been processed; no action was taken.',
            'data' => [
                'gateway' => $guard->gateway,
                'deliveries' => $guard->deliveries,
                'first_seen_at' => optional($guard->first_seen_at)->toIso8601String(),
                'last_seen_at' => optional($guard->last_seen_at)->toIso8601String(),
            ],
        ], 200);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function reject(string $message, array $context = [], int $status = 403): JsonResponse
    {
        Log::warning('Webhook rejected.', $context + ['message' => $message]);

        return new JsonResponse([
            'success' => false,
            'error' => [
                'code' => 'webhook_rejected',
                'message' => $message,
                'details' => (object) $context,
            ],
        ], $status);
    }
}
