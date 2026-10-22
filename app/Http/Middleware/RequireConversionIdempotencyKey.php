<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantees that a request which can move money carries an idempotency key.
 *
 * WHY THIS EXISTS WHEN THE ENGINE ALREADY REQUIRES A KEY
 * The engine's requirement is the one that counts, and it stays. This middleware
 * sits in front of it for two reasons that are about the client, not the money.
 * First, a missing key should be answered before a request DTO, a policy
 * decision and a route resolution are built — the expensive part of a refusal is
 * everything that happens before the engine gets to say no. Second, and more
 * importantly, an API client that gets `422 idempotency_key_required` learns
 * nothing about how to succeed. The response below states the rule — the header
 * name, the minimum length, the maximum length — so that fixing the integration
 * does not require reading this repository.
 *
 * WHY THE HEADER IS THE PRIMARY SOURCE
 * A money-moving POST is the one place where a transport-level identifier
 * belongs in a header: it survives form encoding, it is not shown in a URL, and
 * a retry library can attach it without touching the body. The body field is
 * still accepted, because a mobile client that has already shipped uses it, and
 * refusing a working integration to enforce a style preference would be
 * vandalism rather than rigour.
 *
 * WHAT IT DOES NOT DO
 * It does not generate a key on the member's behalf. A server-generated key
 * would be different on every retry, so a duplicate submission from a flaky
 * connection would move money twice while looking perfectly idempotent. The key
 * must come from the party that knows whether this is a retry — the client.
 */
class RequireConversionIdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        // Reading only: a GET (history, quote lookup) moves nothing and has
        // nothing to de-duplicate.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if (! (bool) config('conversions.idempotency.required', true)) {
            return $next($request);
        }

        $key = $this->keyFrom($request);
        $minimum = (int) config('conversions.idempotency.min_key_length', 16);
        $maximum = (int) config('conversions.idempotency.max_key_length', 128);

        if ($key === null) {
            return $this->refuse(
                $request,
                'idempotency_key_required',
                'This request moves money and must carry an idempotency key.',
                [
                    'header' => 'Idempotency-Key',
                    'body_field' => 'idempotency_key',
                    'min_length' => $minimum,
                    'max_length' => $maximum,
                ],
            );
        }

        if (strlen($key) < $minimum) {
            return $this->refuse(
                $request,
                'idempotency_key_too_short',
                sprintf('The idempotency key must be at least %d characters.', $minimum),
                ['length' => strlen($key), 'min_length' => $minimum],
            );
        }

        if (strlen($key) > $maximum) {
            return $this->refuse(
                $request,
                'idempotency_key_too_long',
                sprintf('The idempotency key must be at most %d characters.', $maximum),
                ['length' => strlen($key), 'max_length' => $maximum],
            );
        }

        // Normalised onto the request so that every downstream consumer — the
        // request DTO, the audit trail and the engine — reads the same value
        // from the same place, whatever transport the client used.
        $request->merge(['idempotency_key' => $key]);

        return $next($request);
    }

    private function keyFrom(Request $request): ?string
    {
        $candidates = [
            $request->headers->get('Idempotency-Key'),
            $request->headers->get('X-Idempotency-Key'),
            $request->input('idempotency_key'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function refuse(Request $request, string $code, string $message, array $details): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return ApiResponse::error(code: $code, message: $message, status: 422, details: $details);
        }

        abort(422, $message);
    }
}
