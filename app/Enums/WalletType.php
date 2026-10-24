<?php

namespace App\Enums;

enum WalletType: string
{
    case Primary = 'primary';
    case Main = 'main';
    case Win = 'win';
    case Bonus = 'bonus';
    case Commission = 'commission';

    public function label(): string
    {
        return match ($this) {
            self::Primary, self::Main => 'Primary Wallet',
            self::Win => 'Winnings Wallet',
            self::Bonus => 'Bonus Wallet',
            self::Commission => 'Commission Wallet',
        };
    }

    public function canWithdraw(): bool
    {
        return in_array($this, [self::Primary, self::Main, self::Win, self::Commission], true);
    }

    public function canDeposit(): bool
    {
        return in_array($this, [self::Primary, self::Main], true);
    }

    public function canBet(): bool
    {
        return in_array($this, [self::Primary, self::Main, self::Bonus], true);
    }
}
