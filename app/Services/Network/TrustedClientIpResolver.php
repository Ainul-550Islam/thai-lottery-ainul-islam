<?php

declare(strict_types=1);

namespace App\Services\Network;

use Illuminate\Http\Request;

/**
 * Class TrustedClientIpResolver
 *
 * Resolves the genuine client IP address strictly through configured trusted proxies.
 * Never blindly trusts spoofable client-supplied X-Forwarded-For headers.
 */
class TrustedClientIpResolver
{
    public function __construct(
        private readonly IpCidrMatcher $cidrMatcher
    ) {}

    /**
     * Resolve the authentic client IP address from request context.
     *
     * @param  array<int, string>|null  $overrideTrustedProxies
     */
    public function resolve(Request $request, ?array $overrideTrustedProxies = null): string
    {
        $directIp = (string) $request->server('REMOTE_ADDR', '127.0.0.1');
        $trustedProxies = $overrideTrustedProxies
            ?? (app()->bound('config') ? (array) config('network.trusted_proxies', []) : []);

        // If direct IP is not a configured trusted proxy, return direct IP unconditionally
        if (! $this->isTrustedProxy($directIp, $trustedProxies)) {
            return $directIp;
        }

        // Direct IP is trusted proxy -> evaluate X-Forwarded-For chain backwards
        $forwardedHeader = $request->header('X-Forwarded-For');
        if (! $forwardedHeader) {
            return $directIp;
        }

        $ips = array_map('trim', explode(',', $forwardedHeader));
        $reversedIps = array_reverse($ips);

        foreach ($reversedIps as $candidateIp) {
            if (! filter_var($candidateIp, FILTER_VALIDATE_IP)) {
                continue;
            }

            if (! $this->isTrustedProxy($candidateIp, $trustedProxies)) {
                return $candidateIp; // First untrusted IP is genuine client
            }
        }

        return $directIp;
    }

    /**
     * Alias accepting explicit trusted proxy CIDRs for unit testing without container state.
     *
     * @param  array<int, string>|null  $trustedProxies
     */
    public function resolveClientIp(Request $request, ?array $trustedProxies = null): string
    {
        return $this->resolve($request, $trustedProxies);
    }

    private function isTrustedProxy(string $ip, array $trustedProxies): bool
    {
        if (empty($trustedProxies)) {
            return false;
        }

        foreach ($trustedProxies as $trustedRange) {
            if ($this->cidrMatcher->matches($ip, $trustedRange)) {
                return true;
            }
        }

        return false;
    }
}
