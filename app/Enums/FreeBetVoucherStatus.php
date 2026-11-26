<?php

declare(strict_types=1);

namespace App\Enums;

enum FreeBetVoucherStatus: string
{
    case Active = 'active';
    case Consumed = 'consumed';
    case Expired = 'expired';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Consumed => 'Consumed',
            self::Expired => 'Expired',
            self::Void => 'Void',
        };
    }
}
