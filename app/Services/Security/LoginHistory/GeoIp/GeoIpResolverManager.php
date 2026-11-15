<?php

declare(strict_types=1);

namespace App\Services\Security\LoginHistory\GeoIp;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class GeoIpResolverManager implements GeoIpResolver
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly NullGeoIpResolver $nullResolver,
    ) {}

    public function driver(?string $name = null): GeoIpResolver
    {
        $configured = $name
            ?? $this->config->get('rewards.geoip.driver');

        $driver = is_string($configured) ? strtolower(trim($configured)) : '';

        return match ($driver) {
            'null', '' => $this->nullResolver,
            default => $this->nullResolver,
        };
    }

    public function resolve(?string $ip): ?GeoIpResult
    {
        return $this->driver()->resolve($ip);
    }
}
