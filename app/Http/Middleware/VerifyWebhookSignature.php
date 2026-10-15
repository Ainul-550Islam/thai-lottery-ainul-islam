<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\WebhookReplayGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Durable observation layer for inbound payment webhooks.
 *
 * Cryptographic verification remains in each gateway driver because provider
 * protocols differ (for example, Stripe signs a timestamp plus the raw body,
 * while other drivers use their own HMAC formats). This middleware therefore
 * does NOT authenticate a webhook and must never be treated as a substitute for
 * the driver's signature verification.
 *
 * After the controller accepts a request, this middleware records a durable
 * fingerprint of the signature and raw body. A database unique key makes the
 * observation atomic across application instances; repeat deliveries update
 * the same row's delivery count. This is telemetry and a cross-instance
 * duplicate signal, not a pre-controller rejection. Payment state transitions
 * and idempotency keys remain responsible for ensuring a retry cannot apply a
 * financial mutation twice.
 *
 * Requests with a missing signature are passed to the controller without a
 * receipt so the gateway driver can return its normal fail-closed response.
 */
final class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next, ?string $gateway = null): Response
    {
        $gateway = strtolower(trim($gateway ?? (string) $request->route('gateway')));

        if (! (bool) config('payment.webhook.replay_protection', true)) {
            return $next($request);
        }

        if ($gateway === '' || ! preg_match('/\A[a-z0-9_-]{1,32}\z/D', $gateway)) {
            // The controller/gateway manager is responsible for the normal
            // invalid-gateway response. Do not turn this observer into a router.
            return $next($request);
        }

        $headerName = (string) config("payment.gateways.{$gateway}.signature_header", 'X-Signature');
        $signature = trim((string) $request->header($headerName, ''));

        if ($signature === '') {
            return $next($request);
        }

        // Hash the full provider-supplied header to keep the database key fixed
        // length and avoid storing an attacker-controlled value in an index.
        $nonce = hash('sha256', $gateway.'|'.$signature);
        $payloadHash = hash('sha256', $request->getContent());

        $response = $next($request);

        // Only a response accepted by the controller becomes a durable
        // observation. Invalid signatures and failed requests must remain
        // retryable and must not occupy the receipt key.
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return $response;
        }

        try {
            $isReplay = $this->recordAcceptedDelivery(
                gateway: $gateway,
                nonce: $nonce,
                signature: $signature,
                payloadHash: $payloadHash,
                ipAddress: $request->ip(),
            );

            // Expose the observer's duplicate signal without intercepting or
            // refusing the accepted webhook. Financial idempotency remains the
            // controller/payment state machine's responsibility.
            if ($isReplay && $response instanceof JsonResponse) {
                $data = $response->getData(true);

                if (is_array($data)) {
                    $data['duplicate'] = true;
                    $response->setData($data);
                }
            }
        } catch (Throwable $exception) {
            // Receipt persistence is observability, not payment settlement.
            // Never convert a completed, idempotent financial operation into a
            // failed HTTP response because telemetry could not be written.
            Log::error('Accepted webhook delivery could not be recorded.', [
                'gateway' => $gateway,
                'nonce' => $nonce,
                'error' => get_class($exception),
            ]);
        }

        return $response;
    }

    private function recordAcceptedDelivery(
        string $gateway,
        string $nonce,
        string $signature,
        string $payloadHash,
        ?string $ipAddress,
    ): bool {
        $now = now();
        $retentionDays = max(1, (int) config('payment.webhook.replay_guard_retention_days', 30));
        $expiresAt = $now->copy()->addDays($retentionDays);

        // The unique (gateway, nonce) index plus a single upsert removes the
        // read-then-write race across concurrent workers and database vendors.
        WebhookReplayGuard::query()->upsert(
            [[
                'gateway' => $gateway,
                'nonce' => $nonce,
                // Do not persist the provider's reusable proof verbatim.
                'signature' => hash('sha256', $signature),
                'payload_hash' => $payloadHash,
                'deliveries' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => $expiresAt,
                'first_ip' => $ipAddress,
                'last_ip' => $ipAddress,
            ]],
            ['gateway', 'nonce'],
            [
                'deliveries' => DB::raw('deliveries + 1'),
                'last_seen_at' => $now,
                'expires_at' => $expiresAt,
                'last_ip' => $ipAddress,
            ],
        );

        // A fresh signature for an already-seen body is a provider retry with
        // signature rotation (Stripe does this when it retries with a new time).
        // Record it rather than rejecting it; the state machine is idempotent.
        $distinctSignaturesForBody = WebhookReplayGuard::query()
            ->where('gateway', $gateway)
            ->where('payload_hash', $payloadHash)
            ->distinct()
            ->count('nonce');

        if ($distinctSignaturesForBody > 1) {
            Log::warning('webhook.replay_signature_rotated', [
                'gateway' => $gateway,
                'payload_hash' => $payloadHash,
                'distinct_signatures' => $distinctSignaturesForBody,
            ]);
        }

        $deliveryCount = (int) WebhookReplayGuard::query()
            ->where('gateway', $gateway)
            ->where('nonce', $nonce)
            ->value('deliveries');

        return $deliveryCount > 1;
    }
}
