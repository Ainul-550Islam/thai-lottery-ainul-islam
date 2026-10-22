<?php

declare(strict_types=1);

namespace App\Services\Network;

/**
 * Class IpCidrMatcher
 *
 * High-performance bitwise matcher for IPv4 and IPv6 addresses and CIDR subnets.
 */
class IpCidrMatcher
{
    /**
     * Check if an IP address matches a single IP or CIDR block.
     *
     * @param  string  $ip  Client IP (e.g., '192.168.1.5' or '2001:db8::1')
     * @param  string  $range  IP or CIDR range (e.g., '192.168.1.0/24', '10.0.0.1', '2001:db8::/32')
     */
    public function matches(string $ip, string $range): bool
    {
        $ip = trim($ip);
        $range = trim($range);

        if ($ip === '' || $range === '') {
            return false;
        }

        // If range does not contain '/', treat as exact single-IP comparison
        if (! str_contains($range, '/')) {
            return $this->compareExactIp($ip, $range);
        }

        [$subnet, $prefixStr] = explode('/', $range, 2);
        if (! is_numeric($prefixStr)) {
            return false;
        }

        $prefix = (int) $prefixStr;

        $isIpV6 = str_contains($ip, ':');
        $isSubnetV6 = str_contains($subnet, ':');

        // IP version mismatch cannot match
        if ($isIpV6 !== $isSubnetV6) {
            return false;
        }

        return $isIpV6
            ? $this->matchesIpv6Cidr($ip, $subnet, $prefix)
            : $this->matchesIpv4Cidr($ip, $subnet, $prefix);
    }

    /**
     * Alias retained for caller/test parity.
     */
    public function ipMatchesPattern(string $ip, string $range): bool
    {
        return $this->matches($ip, $range);
    }

    /**
     * Compare two IPs with canonical normalization (handles IPv6 compression).
     */
    private function compareExactIp(string $ip1, string $ip2): bool
    {
        $packed1 = @inet_pton($ip1);
        $packed2 = @inet_pton($ip2);

        if ($packed1 === false || $packed2 === false) {
            return false;
        }

        return $packed1 === $packed2;
    }

    /**
     * Match IPv4 address against CIDR subnet.
     */
    private function matchesIpv4Cidr(string $ip, string $subnet, int $prefix): bool
    {
        if ($prefix < 0 || $prefix > 32) {
            return false;
        }

        if ($prefix === 0) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = ~((1 << (32 - $prefix)) - 1);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * Match IPv6 address against CIDR subnet using binary packing.
     */
    private function matchesIpv6Cidr(string $ip, string $subnet, int $prefix): bool
    {
        if ($prefix < 0 || $prefix > 128) {
            return false;
        }

        if ($prefix === 0) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        $ipPacked = @inet_pton($ip);
        $subnetPacked = @inet_pton($subnet);

        if ($ipPacked === false || $subnetPacked === false) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        // Compare full bytes
        if ($fullBytes > 0 && substr($ipPacked, 0, $fullBytes) !== substr($subnetPacked, 0, $fullBytes)) {
            return false;
        }

        // Compare remaining bits
        if ($remainingBits > 0) {
            $mask = 0xFF << (8 - $remainingBits) & 0xFF;
            $ipByte = ord($ipPacked[$fullBytes]);
            $subnetByte = ord($subnetPacked[$fullBytes]);

            if (($ipByte & $mask) !== ($subnetByte & $mask)) {
                return false;
            }
        }

        return true;
    }
}
