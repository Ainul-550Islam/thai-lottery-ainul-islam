<?php

namespace App\Enums;

enum WalletType: string
{
    case Primary = 'primary';
    case Bonus = 'bonus';
    case Commission = 'commission';

    /**
     * The playable winnings wallet. Win holds stakeable value only: it cannot
     * be withdrawn directly. It leaves the platform through the priced
     * Win -> Cash conversion lane, after which the Cash (primary) wallet is
     * the side that can be withdrawn.
     */
    case Win = 'win';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary Wallet',
            self::Bonus => 'Bonus Wallet',
            self::Commission => 'Commission Wallet',
            self::Win => 'Win Wallet',
        };
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
        return in_array($this, [self::Primary, self::Bonus, self::Win], true);
    }
}
