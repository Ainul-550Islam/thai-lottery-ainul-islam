<?php

declare(strict_types=1);

namespace App\Enums;

enum CashbackAccrualStatus: string
{
    case Accrued = 'accrued';
    case Claimed = 'claimed';
    case Expired = 'expired';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Accrued => 'Accrued',
            self::Claimed => 'Claimed',
            self::Expired => 'Expired',
            self::Reversed => 'Reversed',
        };
    }

    public function isClaimable(): bool
    {
        return $this === self::Accrued;
    }
}
