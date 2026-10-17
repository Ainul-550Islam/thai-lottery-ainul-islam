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
     */
    public function resolve(Request $request): string
    {
        $directIp = (string) $request->server('REMOTE_ADDR', '127.0.0.1');
        $trustedProxies = (array) config('network.trusted_proxies', []);

        // If direct IP is not a configured trusted proxy, return direct IP unconditionally
        if (!$this->isTrustedProxy($directIp, $trustedProxies)) {
            return $directIp;
        }

        // Direct IP is trusted proxy -> evaluate X-Forwarded-For chain backwards
        $forwardedHeader = $request->header('X-Forwarded-For');
        if (!$forwardedHeader) {
            return $directIp;
        }

        $ips = array_map('trim', explode(',', $forwardedHeader));
        $reversedIps = array_reverse($ips);

        foreach ($reversedIps as $candidateIp) {
            if (!filter_var($candidateIp, FILTER_VALIDATE_IP)) {
                continue;
            }

            if (!$this->isTrustedProxy($candidateIp, $trustedProxies)) {
                return $candidateIp; // First untrusted IP is genuine client
            }
        }

        return $directIp;
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
