<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\WebhookReceipt;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify an inbound payment/provider webhook.
 *
 * ============================================================================
 * WHAT WAS WRONG WITH THE PREVIOUS VERSION
 * ============================================================================
 * The original middleware verified an HMAC-SHA256 signature over the raw body.
 * That is the right primitive, and it was correctly using hash_hmac +
 * hash_equals (timing-safe, no `==`). Two things were missing, and one of them
 * is a P0 on a money path:
 *
 *  1. NO REPLAY PROTECTION. A captured webhook body plus its signature stayed
 *     valid forever. An attacker who observed one legitimate
 *     `deposit.completed` delivery — or who had read-only access to a log, a
 *     proxy, or an error tracker — could re-POST it indefinitely. The service
 *     layer's idempotency key (`whk:{provider}:{externalId}`) prevents the
 *     second delivery from crediting the wallet twice, which is what stops this
 *     from being a direct double-credit. It does NOT stop unlimited replay
 *     traffic, does not stop a replayed `refund` or `chargeback` body from
 *     re-entering the state machine, and it makes every replay cost a full
 *     database transaction before being discarded. Replay must be refused at
 *     the edge, before any handler runs.
 *
 *  2. NO TIMESTAMP TOLERANCE WINDOW. Because no signed timestamp was
 *     considered, there was no notion of a webhook being "too old" to accept.
 *
 * ============================================================================
 * WHAT THIS VERSION DOES
 * ============================================================================
 *  1. Resolves the signature header and the timestamp header per gateway.
 *  2. Requires a timestamp and enforces a tolerance window (default 300s, both
 *     directions — a future timestamp is as suspicious as an old one).
 *  3. Binds the timestamp INTO the signed material, so a timestamp cannot be
 *     edited to extend a signature's life. This is the critical detail: signing
 *     `body` alone and trusting a separate `X-Timestamp` header would let an
 *     attacker rewrite the header freely.
 *  4. Claims a nonce in the cache with `add()` — an atomic
 *     SET NX. Two concurrent identical deliveries cannot both win.
 *  5. Records the receipt so replays are visible as an operational signal
 *     rather than silently swallowed.
 *
 * SIGNATURE SCHEME (the provider contract)
 *   signed_payload = "<timestamp>.<raw body>"
 *   signature      = hex(hmac_sha256(signed_payload, webhook_secret))
 *
 * Config, per gateway:
 *   payment.gateways.{gw}.webhook_secret        (required)
 *   payment.gateways.{gw}.signature_header      (default X-Signature)
 *   payment.gateways.{gw}.timestamp_header      (default X-Timestamp)
 *   payment.gateways.{gw}.signature_prefix      (default '', e.g. 'sha256=')
 *   payment.gateways.{gw}.tolerance_seconds     (default security.webhooks.tolerance_seconds)
 *
 * MIGRATION NOTE FOR EXISTING INTEGRATIONS
 * A provider that cannot sign a timestamp cannot use this middleware. For such a
 * gateway set `tolerance_seconds: 0` explicitly to acknowledge that replay
 * protection is off, which records the decision in configuration instead of
 * leaving it as an accident. It is never a safe production setting for a
 * gateway that moves money.
 */
class VerifyWebhookSignature
{
    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function handle(Request $request, Closure $next, ?string $gateway = null): Response
    {
        $gateway = $gateway ?? (string) $request->route('gateway');
        $gateway = trim($gateway);

        if ($gateway === '') {
            return $this->reject('Webhook gateway could not be resolved.', [
                'gateway' => null,
                'severity' => 'critical',
            ]);
        }

        $secret = config("payment.gateways.{$gateway}.webhook_secret");

        if (! is_string($secret) || $secret === '') {
            // Fail closed: an unconfigured secret must never mean "skip
            // verification". That is the classic way a signing control is
            // disabled by an empty .env value.
            return $this->reject('Webhook signature verification is not configured.', [
                'gateway' => $gateway,
                'severity' => 'critical',
            ]);
        }

        $body = $request->getContent();

        if (! is_string($body) || $body === '') {
            return $this->reject('Webhook body is empty; nothing could have been signed.', [
                'gateway' => $gateway,
            ]);
        }

        $signatureHeader = (string) config("payment.gateways.{$gateway}.signature_header", 'X-Signature');
        $timestampHeader = (string) config("payment.gateways.{$gateway}.timestamp_header", 'X-Timestamp');
        $prefix = (string) config("payment.gateways.{$gateway}.signature_prefix", '');
        $tolerance = (int) config(
            "payment.gateways.{$gateway}.tolerance_seconds",
            config('security.webhooks.tolerance_seconds', 300),
        );

        $provided = trim((string) $request->header($signatureHeader, ''));
        $timestamp = trim((string) $request->header($timestampHeader, ''));

        if ($provided === '') {
            return $this->reject('Missing webhook signature.', ['gateway' => $gateway]);
        }

        if ($prefix !== '' && str_starts_with($provided, $prefix)) {
            $provided = substr($provided, strlen($prefix));
        }

        if ($timestamp === '') {
            return $this->reject('Missing webhook timestamp; replay protection cannot be applied.', [
                'gateway' => $gateway,
            ]);
        }

        if (! ctype_digit($timestamp)) {
            return $this->reject('Webhook timestamp is not an integer.', ['gateway' => $gateway]);
        }

        // ── Tolerance window. Zero disables only the window, never the nonce.
        if ($tolerance > 0) {
            $drift = abs(time() - (int) $timestamp);

            if ($drift > $tolerance) {
                return $this->reject('Webhook timestamp is outside the tolerance window.', [
                    'gateway' => $gateway,
                    'drift_seconds' => $drift,
                    'tolerance_seconds' => $tolerance,
                ]);
            }
        }

        // ── Verify the signature over "<timestamp>.<body>".
        //    The timestamp is inside the signed material, so editing the header
        //    invalidates the signature.
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        if (! hash_equals($expected, strtolower($provided))) {
            return $this->reject('Invalid webhook signature.', [
                'gateway' => $gateway,
                'severity' => 'critical',
            ]);
        }

        // ── Single-use nonce claim. Cache::add() is atomic (SET NX): under
        //    concurrent duplicate delivery exactly one caller wins.
        $window = max($tolerance, 300);
        $nonce = hash('sha256', $gateway.'|'.$timestamp.'|'.$expected);
        $claimed = $this->cache->add("webhook:nonce:{$nonce}", 1, $window);

        if (! $claimed) {
            $this->record($gateway, $request, $nonce, $timestamp, WebhookReceipt::STATUS_REPLAYED);

            Log::warning('Webhook rejected: replayed signature.', [
                'gateway' => $gateway,
                'nonce' => $nonce,
                'timestamp' => $timestamp,
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->reject('Webhook has already been processed.', [
                'gateway' => $gateway,
                'reason' => 'replay',
            ], 409);
        }

        $this->record($gateway, $request, $nonce, $timestamp, WebhookReceipt::STATUS_ACCEPTED);

        // Hand the verified facts downstream so the handler does not re-derive
        // them (and cannot accidentally trust an unverified header).
        $request->attributes->set('webhook.gateway', $gateway);
        $request->attributes->set('webhook.nonce', $nonce);
        $request->attributes->set('webhook.timestamp', (int) $timestamp);
        $request->attributes->set('webhook.signature_verified', true);

        return $next($request);
    }

    /**
     * Persist the receipt. Best-effort by design: an observability write must
     * never be the reason a legitimate webhook is refused.
     */
    private function record(
        string $gateway,
        Request $request,
        string $nonce,
        string $timestamp,
        string $status,
    ): void {
        if (! (bool) config('security.webhooks.persist_receipts', true)) {
            return;
        }

        try {
            WebhookReceipt::query()->create([
                'gateway' => $gateway,
                'nonce' => $nonce,
                'signature_timestamp' => (int) $timestamp,
                'status' => $status,
                'ip_address' => $request->ip(),
                'path' => $request->path(),
                'received_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Webhook receipt could not be recorded.', [
                'gateway' => $gateway,
                'nonce' => $nonce,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function reject(string $message, array $context = [], int $status = 403): Response
    {
        Log::warning('Webhook rejected.', $context + ['message' => $message]);

        return response()->json([
            'error' => 'webhook_rejected',
            'message' => $message,
        ], $status);
    }
}
