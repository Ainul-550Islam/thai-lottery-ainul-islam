<?php

declare(strict_types=1);

namespace App\Enums;

enum GloL6ReservationStatus: string
{
    case Reserved = 'reserved';
    case Purchased = 'purchased';
    case Released = 'released';
    case Expired = 'expired';
    case RolledBack = 'rolled_back';
    case Refunded = 'refunded';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Released,
            self::Expired,
            self::RolledBack,
            self::Refunded,
        ], true);
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;
        }

        return match ($this) {
            self::Reserved => in_array($next, [self::Purchased, self::Released, self::Expired, self::RolledBack], true),
            self::Purchased => $next === self::Refunded,
            self::Released, self::Expired, self::RolledBack, self::Refunded => false,
        };
    }
}
