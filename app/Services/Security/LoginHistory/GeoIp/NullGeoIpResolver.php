<?php

declare(strict_types=1);

namespace App\Services\Security\LoginHistory\GeoIp;

final class NullGeoIpResolver implements GeoIpResolver
{
    public function resolve(?string $ip): ?GeoIpResult
    {
        return null;
    }
}
