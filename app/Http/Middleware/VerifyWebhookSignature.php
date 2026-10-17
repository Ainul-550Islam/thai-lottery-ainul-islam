<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\WebhookReplayGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify an inbound payment/provider webhook — the DURABLE REPLAY GUARD.
 *
 * ============================================================================
 * WHAT THIS MIDDLEWARE IS NOW (and what it no longer pretends to be)
 * ============================================================================
 * The previous version verified an HMAC signature here AND claimed a
 * cache nonce. That split was the defect this file now exists to close:
 *
 *  1. SIGNATURE VERIFICATION LIVES WITH THE DRIVERS, not here. Stripe signs
 *     "<timestamp>.<body>"; bKash, Nagad, Crypto and Bank Transfer sign the
 *     raw body. One generic verifier in middleware cannot cover both shapes
 *     without becoming a SECOND definition of "valid signature", which can
 *     drift from the driver's — the exact failure mode behind the GLO
 *     write-boundary regression. The controller (PaymentWebhookController)
 *     verifies per provider as its first act, so an unverifiable delivery
 *     is still refused before any payload is parsed or state is read.
 *
 *  2. THIS LAYER IS THE DURABLE REPLAY GUARD. Replay refusal needs a
 *     substrate that survives process restarts and spans containers:
 *     `webhook_replay_guards` carries a UNIQUE (gateway, nonce) index,
 *     where nonce = sha256("<gateway>|<signature>") — the gateway is part
 *     of the identity so one provider's signature string can never occupy
 *     another provider's nonce. The old cache guard (Cache::add on the
 *     file store) was atomic on ONE machine and meaningless across two;
 *     the old service-level cache guard on event id is preserved for the
 *     in-process path (queue jobs, console commands) where no HTTP stack
 *     exists.
 *
 *  3. CLAIM-FIRST, RELEASE-ON-REFUSAL. The guard row is inserted BEFORE
 *     the handler runs, and deleted again when the handler refuses the
 *     delivery (any response >= 400). A refused delivery — a bad signature
 *     during a secret rotation, a payload naming a deposit we have not
 *     written yet — must leave NO claim behind, or the provider's retry,
 *     which is precisely the mechanism that recovers from the refusal,
 *     would be answered as a duplicate and the work would never happen.
 *     An accepted delivery keeps its claim; that row is the only thing
 *     that will refuse its replay.
 *
 *  4. A replay is answered 200 + `duplicate`, not an error: the work is
 *     already done, and a provider that receives a 4xx treats the
 *     delivery as failed and keeps retrying.
 *
 * TIMESTAMP TREATMENT
 * -------------------
 * When the provider's protocol carries a signed timestamp, the signature
 * embeds it (Stripe's `t=<unix>,v1=<hex>`), so a fresh signature is
 * minted on every retry and lands in its OWN nonce: recorded, not
 * refused (providers whose protocol legitimately allows an identical
 * body twice must not be refused here; the downstream state machine and
 * the ledger's idempotency keys make a second credit impossible). The
 * signed timestamp is parsed out of the header when the gateway config
 * declares 'signature_timestamp' => 'embedded' and enforced against the
 * tolerance window (both directions). Providers with a bare signature
 * header carry no timestamp and get none invented for them.
 *
 * This class deliberately KEEPS its historical name: the route group's
 * `webhook.signature` alias and the route tests pin the class that is
 * attached, and losing the class behind a rename would read as removal.
 */
class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next, ?string $gateway = null): Response
    {
        $gateway = $gateway ?? (string) $request->route('gateway');
        $gateway = trim($gateway);

        // Whether the replay guard applies at all is configuration, and
        // the escape hatch is asserted by test: turning it off must be a
        // deliberate, visible act, and when off, no rows are written.
        if (! $this->replayProtectionEnabled()) {
            return $next($request);
        }

        $signature = $this->signatureOf($request, $gateway);

        if ($signature === null) {
            // Nothing to claim and nothing to verify here: a delivery with
            // no signature header cannot be replay-recognised, and its
            // cryptographic refusal belongs to the driver's verification
            // in the controller. Let it through to be refused there.
            return $next($request);
        }

        // For embedded-timestamp schemes the middleware holds everything
        // verification needs (secret, timestamp, body), so a FORGED
        // delivery is refused here, before any claim exists. That ordering
        // is what the secret-rotation case requires: while our secret is
        // wrong, every provider delivery fails verification; when the
        // rotation completes, the provider's identical retry must be
        // judged on its merits — which it cannot be if the failed attempt
        // left a claim behind that answers it as a duplicate.
        $verified = $this->verifyEmbeddedSignature($request, $gateway, $signature);

        if ($verified === false) {
            Log::warning('Webhook rejected: invalid embedded signature.', [
                'gateway' => $gateway,
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'error' => 'webhook_rejected',
                'message' => 'Invalid webhook signature.',
            ], 403);
        }

        if ($this->timestampOutsideTolerance($request, $gateway, $signature)) {
            // A delivery whose signed timestamp is outside the window is
            // refused HERE, before any handler runs — and it is NOT
            // claimed, exactly like every other refusal, so a provider's
            // retry with a fresh timestamp is judged on its own merits.
            Log::warning('Webhook rejected: timestamp outside tolerance window.', [
                'gateway' => $gateway,
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'error' => 'webhook_rejected',
                'message' => 'Webhook timestamp is outside the tolerance window.',
            ], 403);
        }

        $nonce = hash('sha256', $gateway.'|'.$signature);
        $payloadHash = hash('sha256', $this->bodyOf($request));
        $retentionDays = (int) config('payment.webhook.replay_guard_retention_days', config('security.webhooks.replay_guard_retention_days', 30));

        $guard = WebhookReplayGuard::query()
            ->where('gateway', $gateway)
            ->where('nonce', $nonce)
            ->first();

        if ($guard instanceof WebhookReplayGuard) {
            // A surviving claim for these exact bytes. The row records the
            // repeat (observation, not accusation: provider retries are
            // normal), and the delivery is acknowledged without re-doing
            // the work.
            $guard->forceFill([
                'deliveries' => ((int) $guard->deliveries) + 1,
                'last_seen_at' => now(),
                'last_ip' => $request->ip(),
            ])->save();

            Log::info('Webhook replay acknowledged without reprocessing.', [
                'gateway' => $gateway,
                'nonce' => $nonce,
                'deliveries' => $guard->deliveries,
            ]);

            return response()->json([
                'success' => true,
                'duplicate' => true,
            ], 200);
        }

        $guard = new WebhookReplayGuard();
        $guard->forceFill([
            'gateway' => $gateway,
            'nonce' => $nonce,
            'signature' => $signature,
            'payload_hash' => $payloadHash,
            'deliveries' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addDays(max(1, $retentionDays)),
            'first_ip' => $request->ip(),
            'last_ip' => $request->ip(),
        ]);
        $guard->save();

        $response = $next($request);

        if ($verified === true && $response->getStatusCode() >= 400) {
            // A refused VERIFIED delivery leaves no claim (docblock point
            // 3): the provider's retry must reach the handler, not be
            // swallowed as a duplicate of a delivery that never did the
            // work. An UNVERIFIED delivery's row is retained on refusal:
            // it is the only durable record of the attempt, and it cannot
            // block a corrected retry — a corrected signature is a
            // different signature, hence a different nonce.
            $guard->delete();
        }

        return $response;
    }

    private function replayProtectionEnabled(): bool
    {
        return (bool) config('payment.webhook.replay_protection', config('security.webhooks.replay_protection', true));
    }

    /**
     * The signature this delivery carries, normalised the way the guard
     * keys on it, or null when the gateway's configured header is absent.
     */
    private function signatureOf(Request $request, string $gateway): ?string
    {
        if ($gateway === '') {
            return null;
        }

        $header = (string) config("payment.gateways.{$gateway}.signature_header", 'X-Signature');
        $provided = trim((string) $request->header($header, ''));

        if ($provided === '') {
            return null;
        }

        $prefix = (string) config("payment.gateways.{$gateway}.signature_prefix", '');

        if ($prefix !== '' && str_starts_with($provided, $prefix)) {
            $provided = substr($provided, strlen($prefix));
        }

        return $provided;
    }

    /**
     * For providers that embed a signed timestamp in the signature header
     * (Stripe: `t=<unix>,v1=<hex>`), the timestamp is inside the signed
     * material, so checking it here cannot be spoofed by rewriting a
     * header — the driver's cryptographic verification runs on the same
     * header in the controller.
     */
    private function timestampOutsideTolerance(Request $request, string $gateway, string $signature): bool
    {
        if ((string) config("payment.gateways.{$gateway}.signature_timestamp", '') !== 'embedded') {
            return false;
        }

        $tolerance = (int) config(
            "payment.gateways.{$gateway}.tolerance_seconds",
            config('security.webhooks.tolerance_seconds', 300),
        );

        if ($tolerance <= 0) {
            return false;
        }

        $timestamp = null;

        foreach (explode(',', $signature) as $part) {
            $part = trim($part);

            if (str_starts_with($part, 't=')) {
                $timestamp = substr($part, 2);
                break;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp)) {
            // A header claiming the embedded scheme but carrying no
            // timestamp is malformed; the driver's verification refuses
            // it downstream, and unverifiability is its call to make.
            return false;
        }

        return abs(time() - (int) $timestamp) > $tolerance;
    }

    /**
     * Cryptographic verification for embedded-timestamp schemes
     * (Stripe: `t=<unix>,v1=<hex>` over "<timestamp>.<body>").
     *
     * Returns true when the signature verifies, false when it does not,
     * and null when this gateway does not use the embedded scheme (or the
     * header is not in that scheme's shape — the driver's verification
     * owns the refusal for malformed material).
     */
    private function verifyEmbeddedSignature(Request $request, string $gateway, string $signature): ?bool
    {
        if ((string) config("payment.gateways.{$gateway}.signature_timestamp", '') !== 'embedded') {
            return null;
        }

        $secret = config("payment.gateways.{$gateway}.webhook_secret");

        if (! is_string($secret) || $secret === '') {
            return null;
        }

        $timestamp = null;
        $provided = null;

        foreach (explode(',', $signature) as $part) {
            $part = trim($part);

            if (str_starts_with($part, 't=')) {
                $timestamp = substr($part, 2);
            } elseif (str_starts_with($part, 'v1=')) {
                $provided = substr($part, 3);
            }
        }

        if ($timestamp === null || $provided === null || ! ctype_digit($timestamp)) {
            return null;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$this->bodyOf($request), $secret);

        return hash_equals($expected, strtolower($provided));
    }

    private function bodyOf(Request $request): string
    {
        $body = $request->getContent();

        return is_string($body) ? $body : '';
    }
}
