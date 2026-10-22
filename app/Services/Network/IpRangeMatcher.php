<?php

declare(strict_types=1);

namespace App\Services\Network;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

/** Literal addresses only: no DNS, ports, wildcards or implicit private networks. */
final class IpRangeMatcher
{
    public function normalizeAddress(mixed $address): ?string
    {
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($address);
        if ($packed === false) {
            return null;
        }
        // Mapped IPv6 represents the same IPv4 identity, not a bypass spelling.
        if ($this->isMapped($packed)) {
            $packed = substr($packed, 12);
        }
        $result = inet_ntop($packed);

        return $result === false ? null : $result;
    }

    public function normalizeRange(mixed $range): ?string
    {
        if (! is_string($range) || strlen($range) > 64) {
            return null;
        }
        $parts = explode('/', $range);
        $address = $this->normalizeAddress($parts[0]);
        if ($address === null || count($parts) > 2) {
            return null;
        }
        if (count($parts) === 1) {
            return $address;
        }
        if (! preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $parts[1])) {
            return null;
        }
        $prefix = (int) $parts[1];
        $packed = inet_pton($parts[0]);
        $maximum = strlen($packed) === 4 ? 32 : 128;
        if ($prefix > $maximum) {
            return null;
        }
        if ($this->isMapped($packed)) {
            if ($prefix < 96) {
                return null;
            }
            $prefix -= 96;
        }

        return $address.'/'.$prefix;
    }

    public function matches(string $address, string $range): bool
    {
        $address = $this->normalizeAddress($address);
        $range = $this->normalizeRange($range);
        if ($address === null || $range === null) {
            throw new InvalidArgumentException('Invalid IP address or range.');
        }

        return IpUtils::checkIp($address, $range);
    }

    private function isMapped(string $packed): bool
    {
        return strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff";
    }
}
