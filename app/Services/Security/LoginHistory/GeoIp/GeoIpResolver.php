<?php

declare(strict_types=1);

namespace App\Services\Security\LoginHistory\GeoIp;

interface GeoIpResolver
{
    public function resolve(?string $ip): ?GeoIpResult;
}
