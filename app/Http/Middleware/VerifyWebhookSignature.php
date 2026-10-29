<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Payment\WebhookReplayGuardService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse already-accepted, byte-identical webhook replays at the HTTP edge.
 *
 * Cryptographic verification remains provider-specific and belongs to the
 * gateway driver / webhook verification service. This middleware deliberately
 * passes missing and invalid signatures downstream so that the driver produces
 * the authoritative refusal response. It persists a replay identity only after
 * that verifier has marked the request verified and the endpoint has returned a
 * successful response.
 */
final class VerifyWebhookSignature
{
    public function __construct(
        private readonly WebhookReplayGuardService $replayGuard,
    ) {}

    public function handle(Request $request, Closure $next, ?string $gateway = null): Response
    {
        $resolvedGateway = strtolower(trim($gateway ?? (string) $request->route('gateway')));

        if ($resolvedGateway === '') {
            // The controller/driver owns unknown-gateway behavior. A replay
            // middleware must not turn a missing route parameter into a signature
            // decision of its own.
            return $next($request);
        }

        // Historical local HMAC observations are not proof of the actual
        // bKash or Nagad provider IPN contracts. Let each closed controller
        // return its refusal rather than turning an old acceptance into a
        // successful replay after the provider lane has been disabled.
        if (in_array($resolvedGateway, ['bkash', 'nagad'], true)) {
            return $next($request);
        }

        if ($this->replayGuard->recordReplayIfAccepted($request, $resolvedGateway)) {
            return response()->json([
                'success' => true,
                'message' => 'Webhook delivery was already accepted.',
                'data' => [
                    'gateway' => $resolvedGateway,
                    'replayed' => true,
                ],
                'duplicate' => true,
            ], 200);
        }

        $request->attributes->set('webhook.gateway', $resolvedGateway);
        $response = $next($request);

        if ($response->isSuccessful()
            && $request->attributes->get('webhook.signature_verified') === true) {
            $this->replayGuard->rememberAccepted($request, $resolvedGateway);
        }

        return $response;
    }
}
