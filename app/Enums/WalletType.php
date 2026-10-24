<?php

declare(strict_types=1);

namespace App\Enums;

enum WalletType: string
{
    case Primary = 'primary';
    case Bonus = 'bonus';
    case Commission = 'commission';
    case Win = 'win';

    public const Main = self::Primary;

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary Wallet',
            self::Bonus => 'Bonus Wallet',
            self::Commission => 'Commission Wallet',
            self::Win => 'Win Wallet',
        };
    }

    public function labelKey(): string
    {
        return 'wallet_conversion.wallet_'.$this->value;
    }

    public function canWithdraw(): bool
    {
        return in_array($this, [self::Primary, self::Commission], true);
    }

    public function canDeposit(): bool
    {
        return $this === self::Primary;
    }

    public function canBet(): bool
    {
        return in_array($this, [self::Primary, self::Bonus], true);
    }

    public function isTransferPocket(): bool
    {
        return $this->transferPocketName() !== null;
    }

    public function transferPocketName(): ?string
    {
        return match ($this) {
            self::Primary => 'cash',
            self::Win => 'win',
            self::Bonus, self::Commission => null,
        };
    }
}
