<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\WebhookReceipt;
use App\Models\WebhookReplayGuard;
use Closure;
use Illuminate\Database\QueryException;
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
 *  1. Resolves the signature scheme and headers per gateway. Stripe's combined
 *     t/v1 header is parsed only for Stripe.
 *  2. For timestamped signatures, requires a timestamp, enforces the configured
 *     tolerance window and binds that timestamp into the signed material.
 *     Body-signed gateways have no timestamp freshness guarantee.
 *  3. Verifies HMAC-SHA256 with hash_equals before attempting any nonce claim.
 *  4. Claims (gateway, nonce) in webhook_replay_guards. The database unique
 *     index, not a per-node cache, decides whether the claim already exists.
 *     An accepted duplicate is acknowledged without re-running the handler.
 *  5. Records best-effort observability receipts. A returned 4xx/5xx releases
 *     this request's claim so the provider can retry the refused delivery.
 *
 * SIGNATURE SCHEMES (selected by signed_material)
 *   timestamped: HMAC-SHA256("<timestamp>.<raw body>", webhook_secret)
 *   body:        HMAC-SHA256("<raw body>", webhook_secret)
 *
 * Config, per gateway:
 *   payment.gateways.{gw}.webhook_secret        (required)
 *   payment.gateways.{gw}.signed_material       (default timestamped)
 *   payment.gateways.{gw}.signature_header      (default X-Signature)
 *   payment.gateways.{gw}.timestamp_header      (default X-Timestamp)
 *   payment.gateways.{gw}.signature_prefix      (default '', e.g. 'sha256=')
 *   payment.gateways.{gw}.tolerance_seconds     (default security.webhooks.tolerance_seconds)
 *
 * Zero tolerance disables only the timestamp window; it does not disable the
 * nonce guard and it does not make a timestamped signature optional. A provider
 * using raw-body signatures must explicitly configure signed_material = body.
 */
class VerifyWebhookSignature
{
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

        // Stripe packs the timestamp and signature into ONE header:
        //   Stripe-Signature: t=<unix>,v1=<hex>[,v1=<hex>]
        // Split it here so a genuine Stripe delivery is verified the same way as
        // every other gateway. The signed material is still "<t>.<body>".
        if ($gateway === 'stripe' && str_contains($provided, 'v1=')) {
            $parts = $this->parseStripeSignatureHeader($provided);
            $provided = $parts['v1'];
            if ($parts['t'] !== '') {
                $timestamp = $parts['t'];
            }
        }

        if ($provided === '') {
            return $this->reject('Missing webhook signature.', ['gateway' => $gateway]);
        }

        if ($prefix !== '' && str_starts_with($provided, $prefix)) {
            $provided = substr($provided, strlen($prefix));
        }

        // Two signing schemes exist, chosen per gateway:
        //   timestamped (Stripe default): signature = HMAC("<timestamp>.<body>")
        //   body                        : signature = HMAC("<body>") - bKash, Nagad, Crypto
        // A body-signed gateway carries no timestamp, so the tolerance window does
        // not apply to it. The durable guard still refuses a repeated signature.
        $signedMaterial = (string) config("payment.gateways.{$gateway}.signed_material", 'timestamped');
        $timestamped = $signedMaterial !== 'body';

        if ($timestamped && $timestamp === '') {
            return $this->reject('Missing webhook timestamp; replay protection cannot be applied.', [
                'gateway' => $gateway,
            ]);
        }

        if ($timestamped && ! ctype_digit($timestamp)) {
            return $this->reject('Webhook timestamp is not an integer.', ['gateway' => $gateway]);
        }

        // ── Tolerance window. Zero disables only the window, never the nonce.
        if ($timestamped && $tolerance > 0) {
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
        $expected = $timestamped
            ? hash_hmac('sha256', $timestamp.'.'.$body, $secret)
            : hash_hmac('sha256', $body, $secret);

        if (! hash_equals($expected, strtolower($provided))) {
            return $this->reject('Invalid webhook signature.', [
                'gateway' => $gateway,
                'severity' => 'critical',
            ]);
        }

        // ── Single-use nonce claim. The database unique index is authoritative
        //    across workers; clearing a node's cache cannot reopen the nonce.
        $nonce = hash('sha256', $gateway.'|'.$timestamp.'|'.$expected);
        $claim = $this->claimDurableGuard($gateway, $nonce, $provided, $body, $request);

        if (! $claim['claimed']) {
            $this->record($gateway, $request, $nonce, $timestamp, WebhookReceipt::STATUS_REPLAYED);

            Log::warning('Webhook rejected: replayed signature.', [
                'gateway' => $gateway,
                'nonce' => $nonce,
                'timestamp' => $timestamp,
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            // A replay of an ACCEPTED delivery is acknowledged with 200, not 4xx.
            // Providers treat any non-2xx as a failed delivery and keep retrying,
            // and the work was already done the first time. The body marks it as
            // a duplicate so nothing downstream runs a second time.
            Log::info('Webhook acknowledged as duplicate.', [
                'gateway' => $gateway,
                'nonce' => $nonce,
            ]);

            return response()->json([
                'success' => true,
                'duplicate' => true,
                'message' => 'Webhook already processed.',
            ], 200);
        }

        $this->record($gateway, $request, $nonce, $timestamp, WebhookReceipt::STATUS_ACCEPTED);

        // Hand the verified facts downstream so the handler does not re-derive
        // them (and cannot accidentally trust an unverified header).
        $request->attributes->set('webhook.gateway', $gateway);
        $request->attributes->set('webhook.nonce', $nonce);
        $request->attributes->set('webhook.timestamp', (int) $timestamp);
        $request->attributes->set('webhook.signature_verified', true);

        $response = $next($request);

        // A delivery the handler refused must leave no claim behind. Otherwise the
        // provider's retry is answered as a duplicate and never reaches the driver,
        // and the payment is silently lost. Only an accepted delivery keeps its claim.
        if ($claim['guard_id'] !== null && $response->getStatusCode() >= 400) {
            $this->releaseDurableGuard($claim['guard_id']);
        }

        return $response;
    }

    /**
     * Claim this signed delivery in the durable guard table.
     *
     * The (gateway, nonce) unique index is the control: a second delivery cannot
     * insert, even under concurrent workers on different hosts. The first claim
     * wins; later deliveries of the same signed bytes are counted as duplicates.
     *
     * Fails closed: any database error other than a unique violation is thrown,
     * so a broken guard can never let a delivery through unchecked.
     *
     * @return array{claimed: bool, guard_id: int|null}
     */
    private function claimDurableGuard(
        string $gateway,
        string $nonce,
        string $signature,
        string $body,
        Request $request,
    ): array {
        if (! (bool) config('payment.webhook.replay_protection', true)) {
            return ['claimed' => true, 'guard_id' => null];
        }

        $now = now();
        $retentionDays = max(1, (int) config('payment.webhook.replay_guard_retention_days', 30));
        $ip = $request->ip();

        try {
            $guard = WebhookReplayGuard::query()->create([
                'gateway' => $gateway,
                'nonce' => $nonce,
                'signature' => substr($signature, 0, 255),
                'payload_hash' => hash('sha256', $body),
                'deliveries' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => $now->copy()->addDays($retentionDays),
                'first_ip' => $ip,
                'last_ip' => $ip,
            ]);

            return ['claimed' => true, 'guard_id' => (int) $guard->getKey()];
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
        }

        WebhookReplayGuard::query()
            ->where('gateway', $gateway)
            ->where('nonce', $nonce)
            ->update(['last_seen_at' => $now, 'last_ip' => $ip]);

        WebhookReplayGuard::query()
            ->where('gateway', $gateway)
            ->where('nonce', $nonce)
            ->increment('deliveries');

        return ['claimed' => false, 'guard_id' => null];
    }

    /**
     * Remove a claim made by this request, because the handler refused it.
     */
    private function releaseDurableGuard(int $guardId): void
    {
        WebhookReplayGuard::query()->whereKey($guardId)->delete();
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');

        return $sqlState === '23000' || str_contains(strtolower($e->getMessage()), 'unique');
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
     * Parse a Stripe-style "t=<unix>,v1=<hex>" header.
     *
     * Returns the first v1 signature and the timestamp. Missing parts come back
     * as '' so the caller's existing fail-closed checks reject the request.
     *
     * @return array{t: string, v1: string}
     */
    private function parseStripeSignatureHeader(string $header): array
    {
        $result = ['t' => '', 'v1' => ''];

        foreach (explode(',', $header) as $pair) {
            $pieces = explode('=', trim($pair), 2);
            if (count($pieces) !== 2) {
                continue;
            }

            [$key, $value] = [trim($pieces[0]), trim($pieces[1])];

            if ($key === 't' && $result['t'] === '') {
                $result['t'] = $value;
            }

            if ($key === 'v1' && $result['v1'] === '') {
                $result['v1'] = strtolower($value);
            }
        }

        return $result;
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
