<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class HmacSignatureVerificationMiddleware
 *
 * Hardened cryptographic HMAC-SHA256 signature and replay-attack defense middleware.
 */
class HmacSignatureVerificationMiddleware
{
    private const MAX_CLOCK_SKEW_SECONDS = 300; // 5 minutes window

    private const NONCE_TTL_SECONDS = 600; // 10 minutes cache

    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-Key');
        $signature = $request->header('X-Signature');
        $timestamp = (int) $request->header('X-Timestamp', 0);
        $nonce = $request->header('X-Nonce');

        if (! $apiKey || ! $signature || ! $timestamp || ! $nonce) {
            return response()->json([
                'success' => false,
                'error' => 'AUTHENTICATION_HEADERS_MISSING',
                'message' => 'Required security headers: X-API-Key, X-Signature, X-Timestamp, X-Nonce.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // 1. Clock skew validation (Prevents stale signature reuse)
        $currentTimestamp = time();
        if (abs($currentTimestamp - $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return response()->json([
                'success' => false,
                'error' => 'REQUEST_EXPIRED',
                'message' => 'Request timestamp is outside the acceptable 300-second window.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // 2. Anti-Replay: Atomic Nonce Verification in Redis
        $nonceKey = "security:nonce:{$apiKey}:{$nonce}";
        /** @var string|null $setResult */
        $setResult = Redis::set($nonceKey, '1', 'EX', self::NONCE_TTL_SECONDS, 'NX');

        if (! $setResult) {
            return response()->json([
                'success' => false,
                'error' => 'REPLAY_ATTACK_DETECTED',
                'message' => 'This request nonce has already been used and cannot be replayed.',
            ], Response::HTTP_FORBIDDEN);
        }

        // 3. Fetch API Client & Secret
        /** @var ApiClient|null $client */
        $client = ApiClient::where('api_key', $apiKey)->where('is_active', true)->first();

        if (! $client) {
            return response()->json([
                'success' => false,
                'error' => 'INVALID_API_KEY',
                'message' => 'API client not found or inactive.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // 4. IP Whitelist Check (if configured)
        if (! empty($client->allowed_ips)) {
            $clientIp = $request->ip();
            if (! in_array($clientIp, $client->allowed_ips, true)) {
                return response()->json([
                    'success' => false,
                    'error' => 'IP_NOT_WHITELISTED',
                    'message' => "Client IP [{$clientIp}] is not permitted for this API Key.",
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // 5. Compute HMAC-SHA256 Signature over Canonical String
        // Canonical format: METHOD|URI_PATH|TIMESTAMP|NONCE|RAW_BODY_SHA256
        $method = strtoupper($request->method());
        $uriPath = '/'.ltrim($request->path(), '/');
        $rawBody = $request->getContent();
        $bodyHash = hash('sha256', $rawBody);

        $canonicalString = implode('|', [
            $method,
            $uriPath,
            (string) $timestamp,
            $nonce,
            $bodyHash,
        ]);

        $expectedSignature = hash_hmac('sha256', $canonicalString, config('app.key').$client->api_key);

        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'success' => false,
                'error' => 'INVALID_HMAC_SIGNATURE',
                'message' => 'Cryptographic signature mismatch. Payload may have been tampered with.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Update last used timestamp
        $client->update(['last_used_at' => now()]);

        return $next($request);
    }
}
