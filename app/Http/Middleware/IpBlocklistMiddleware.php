<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Network\IpBlocklistService;
use App\Services\Network\TrustedClientIpResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class IpBlocklistMiddleware
 *
 * Enforces IP/CIDR deny rules using genuine trusted-client-IP resolution.
 */
class IpBlocklistMiddleware
{
    public function __construct(
        private readonly IpBlocklistService $blocklistService,
        private readonly TrustedClientIpResolver $ipResolver
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!config('network.ip_blocklist_enabled', true)) {
            return $next($request);
        }

        $clientIp = $this->ipResolver->resolve($request);

        if ($this->blocklistService->isBlocked($clientIp)) {
            return response()->json([
                'success' => false,
                'error' => 'NETWORK_ACCESS_DENIED',
                'message' => 'Your network address has been temporarily restricted for security reasons.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
