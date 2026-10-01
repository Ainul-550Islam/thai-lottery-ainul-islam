<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ResponsibleGaming\ResponsibleGamingLimitData;
use App\Enums\ResponsibleGamingLimitType;
use App\Models\ResponsibleGamingLimitVersion;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService as CanonicalService;
use Illuminate\Contracts\Container\Container;

/**
 * Responsible Gaming Limit Service Facade / Delegation Layer.
 */
class ResponsibleGamingLimitService
{
    private CanonicalService $canonical;

    public function __construct(private readonly Container $container)
    {
        $this->canonical = $this->container->make(CanonicalService::class);
    }

    public function pronounce(ResponsibleGamingLimitData $data): array
    {
        return $this->canonical->pronounce($data);
    }

    public function setDailyDepositLimit(int $userId, string $amount, string $currency = 'THB'): ResponsibleGamingLimitVersion
    {
        return $this->canonical->setDailyDepositLimit($userId, $amount, $currency);
    }

    public function updateDailyDepositLimit(int $userId, string $amount, string $currency = 'THB'): ResponsibleGamingLimitVersion
    {
        return $this->canonical->updateDailyDepositLimit($userId, $amount, $currency);
    }

    public function getActiveDailyDepositLimit(int $userId, string $currency = 'THB'): ?string
    {
        return $this->canonical->getActiveDailyDepositLimit($userId, $currency);
    }

    public function setSingleBetLimit(int $userId, string $amount, string $currency = 'THB'): ResponsibleGamingLimitVersion
    {
        return $this->canonical->setSingleBetLimit($userId, $amount, $currency);
    }

    public function getActiveSingleBetLimit(int $userId, string $currency = 'THB'): ?string
    {
        return $this->canonical->getActiveSingleBetLimit($userId, $currency);
    }

    public function setDailyWageringLimit(int $userId, string $amount, string $currency = 'THB'): ResponsibleGamingLimitVersion
    {
        return $this->canonical->setDailyWageringLimit($userId, $amount, $currency);
    }

    public function getActiveDailyWageringLimit(int $userId, string $currency = 'THB'): ?string
    {
        return $this->canonical->getActiveDailyWageringLimit($userId, $currency);
    }

    public function activateDuePending(int $limit = 100): int
    {
        return $this->canonical->activateDuePending($limit);
    }

    public function expireStale(int $limit = 100): int
    {
        return $this->canonical->expireStale($limit);
    }

    public function bindingLimitFor(int $userId, ResponsibleGamingLimitType $type, ?string $currency = null): ?ResponsibleGamingLimitVersion
    {
        return $this->canonical->bindingLimitFor($userId, $type, $currency);
    }
}
