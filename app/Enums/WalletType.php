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
        // Winnings must first use the audited Win-to-Cash conversion lane.
        return in_array($this, [self::Primary, self::Main, self::Commission], true);
    }

    public function canDeposit(): bool
    {
        return in_array($this, [self::Primary, self::Main], true);
    }

    public function canBet(): bool
    {
        return in_array($this, [self::Primary, self::Main, self::Bonus], true);
    }

    /**
     * The pocket key this wallet type resolves to inside the balance-transfer
     * feature, or null when the type is not a transfer pocket.
     *
     * `Main` maps to the same pocket as `Primary`: it is the legacy name for the
     * wallet every deposit lands in, and treating it as a third pocket would give a
     * player two "cash" pockets that cannot be told apart in a transfer.
     *
     * Bonus and Commission deliberately return null. A bonus balance must not be
     * convertible into withdrawable cash through the transfer lane, because that
     * would defeat whatever condition the bonus was issued under.
     */
    public function transferPocketName(): ?string
    {
        return match ($this) {
            self::Primary, self::Main => 'cash',
            self::Win => 'win',
            self::Bonus, self::Commission => null,
        };
    }

    /**
     * Whether this wallet type can take part in a Cash <-> Win transfer.
     */
    public function isTransferPocket(): bool
    {
        return $this->transferPocketName() !== null;
    }

    /**
     * Stable translation key for the member-facing conversion screens.
     *
     * The labels live in lang/<locale>/wallet_conversion.php next to every other
     * string this lane shows, so there is no second translation namespace to drift.
     */
    public function labelKey(): string
    {
        return "wallet_conversion.wallet_{$this->value}";
    }
}
