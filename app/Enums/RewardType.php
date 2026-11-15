<?php

declare(strict_types=1);

namespace App\Enums;

enum RewardType: string
{
    case BonusCash = 'bonus_cash';
    case CashbackBoost = 'cashback_boost';
    case FreeBet = 'free_bet';
    case VipUpgrade = 'vip_upgrade';

    public function label(): string
    {
        return match ($this) {
            self::BonusCash => 'Bonus Cash',
            self::CashbackBoost => 'Cashback Boost',
            self::FreeBet => 'Free Bet Voucher',
            self::VipUpgrade => 'VIP Upgrade',
        };
    }

    public function movesMoney(): bool
    {
        return $this === self::BonusCash;
    }
}
