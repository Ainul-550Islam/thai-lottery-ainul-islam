<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireMfaMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return new JsonResponse([
                'success' => false,
                'error_code' => 'UNAUTHENTICATED',
                'message' => 'Authentication required.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // If user has MFA enabled, check if current session has been verified
        $isMfaEnabled = (bool) ($user->mfa_enabled ?? false);
        $isSessionMfaVerified = (bool) $request->session()->get('mfa_verified', false);

        // API Token capability check
        $tokenCanMfa = $user->currentAccessToken() ? $user->tokenCan('mfa:verified') : false;

        if ($isMfaEnabled && ! $isSessionMfaVerified && ! $tokenCanMfa) {
            return new JsonResponse([
                'success' => false,
                'error_code' => 'MFA_CHALLENGE_REQUIRED',
                'message' => 'Multi-Factor Authentication verification required for this administrative operation.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
