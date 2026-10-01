<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\DTOs\Finance\WalletReservationData;
use App\Models\WalletReservation;
use App\Services\Finance\WalletReservationService as CanonicalWalletReservationService;
use Illuminate\Contracts\Container\Container;

/**
 * Wallet Reservation Facade / Delegation Layer.
 */
class WalletReservationService
{
    private CanonicalWalletReservationService $canonical;

    public function __construct(private readonly Container $container)
    {
        $this->canonical = $this->container->make(CanonicalWalletReservationService::class);
    }

    public function reserve(
        WalletReservationData|int $userIdOrData,
        ?string $amount = null,
        ?string $currency = null,
        ?string $reason = null,
        int $ttlSeconds = 300
    ): array|WalletReservation {
        return $this->canonical->reserve($userIdOrData, $amount, $currency, $reason, $ttlSeconds);
    }

    public function consume(int|string|WalletReservation $reservation, ?string $reason = null): bool|WalletReservation
    {
        return $this->canonical->consume($reservation, $reason);
    }

    public function release(int|string|WalletReservation $reservation, ?string $reason = null): bool|WalletReservation
    {
        return $this->canonical->release($reservation, $reason);
    }

    public function expire(int|string|WalletReservation $reservation): bool|WalletReservation
    {
        return $this->canonical->expire($reservation);
    }
}
