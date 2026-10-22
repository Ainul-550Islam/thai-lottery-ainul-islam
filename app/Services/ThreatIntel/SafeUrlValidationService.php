<?php

declare(strict_types=1);

namespace App\Services\ThreatIntel;

use App\Services\Network\IpCidrMatcher;
use InvalidArgumentException;

/**
 * Class SafeUrlValidationService
 *
 * Hardened SSRF defense engine: Blocks internal network scans, loopbacks,
 * cloud metadata endpoints (169.254.169.254), and DNS rebinding vectors.
 */
class SafeUrlValidationService
{
    private const FORBIDDEN_IP_RANGES = [
        '0.0.0.0/8',          // Current network
        '10.0.0.0/8',         // RFC1918 Private
        '100.64.0.0/10',      // Carrier-grade NAT
        '127.0.0.0/8',        // Loopback
        '169.254.0.0/16',     // Link-local / Cloud metadata (AWS, GCP, Azure)
        '172.16.0.0/12',      // RFC1918 Private
        '192.0.0.0/24',       // IETF Protocol assignments
        '192.0.2.0/24',       // Documentation (TEST-NET-1)
        '192.168.0.0/16',     // RFC1918 Private
        '198.18.0.0/15',      // Benchmarking
        '198.51.100.0/24',    // Documentation (TEST-NET-2)
        '203.0.113.0/24',     // Documentation (TEST-NET-3)
        '224.0.0.0/4',        // Multicast
        '240.0.0.0/4',        // Reserved
        '255.255.255.255/32', // Broadcast
        '::1/128',            // IPv6 Loopback
        'fc00::/7',           // IPv6 Unique-local
        'fe80::/10',          // IPv6 Link-local
    ];

    private readonly IpCidrMatcher $cidrMatcher;

    public function __construct(?IpCidrMatcher $cidrMatcher = null)
    {
        $this->cidrMatcher = $cidrMatcher ?? new IpCidrMatcher;
    }

    /**
     * Non-throwing boolean wrapper around `validateUrl()`.
     */
    public function isSafeExternalUrl(string $url): bool
    {
        try {
            return $this->validateUrl($url);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Validate whether a user-supplied URL is safe to query via outbound HTTP clients.
     *
     * @param  string  $url  The target URL
     *
     * @throws InvalidArgumentException When URL violates SSRF safety boundaries
     */
    public function validateUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if (! $parsed || ! isset($parsed['scheme'], $parsed['host'])) {
            throw new InvalidArgumentException('Malformed URL structure.');
        }

        $scheme = strtolower($parsed['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException("Forbidden protocol scheme [{$scheme}]. Only HTTP/HTTPS permitted.");
        }

        $host = trim($parsed['host'], '[]');

        // Literal IPv4/IPv6 addresses can be validated directly without DNS resolution.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $resolvedIps = [$host];
        } else {
            $resolvedIps = gethostbynamel($host);
            if ($resolvedIps === false || empty($resolvedIps)) {
                throw new InvalidArgumentException("Unable to resolve DNS for hostname [{$host}].");
            }
        }

        foreach ($resolvedIps as $ip) {
            foreach (self::FORBIDDEN_IP_RANGES as $forbiddenRange) {
                if ($this->cidrMatcher->matches($ip, $forbiddenRange)) {
                    throw new InvalidArgumentException(
                        "SSRF Security Violation: Host [{$host}] resolves to restricted internal IP [{$ip}]."
                    );
                }
            }
        }

        return true;
    }
}
