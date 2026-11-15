<?php

declare(strict_types=1);

namespace App\Services\Security\LoginHistory\GeoIp;

final readonly class GeoIpResult
{
    public function __construct(
        public ?string $countryCode,
        public ?string $region = null,
        public ?string $city = null,
    ) {}
}
