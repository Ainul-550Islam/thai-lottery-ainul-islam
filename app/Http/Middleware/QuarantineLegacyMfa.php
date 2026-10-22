<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The legacy verifier accepts a caller-selected secret, not an enrolled factor.
 * Keep its two HTTP routes unavailable until a reviewed replacement exists.
 * This is not a configurable detector or MFA proof/enrollment implementation.
 */
final class QuarantineLegacyMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        // Never inspect credentials, invoke the legacy handler, or mutate a ledger.
        // No configuration/role escape hatch: even privileged callers are refused.
        return ApiResponse::error(
            'mfa_not_configured',
            'Multi-factor authentication is not configured. This endpoint is unavailable.',
            503,
            headers: ['Cache-Control' => 'no-store, private'],
        );
    }
}
