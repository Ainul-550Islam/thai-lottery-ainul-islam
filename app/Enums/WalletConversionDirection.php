<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two directions money can move between the Cash (primary) and Win
 * wallets. Each direction is priced from its own published fee row and can be
 * switched off independently, because closing one side of the lane while the
 * other keeps running is an operator decision the platform actually makes.
 */
enum WalletConversionDirection: string
{
    case CashToWin = 'cash_to_win';
    case WinToCash = 'win_to_cash';

    public function label(): string
    {
        return match ($this) {
            self::CashToWin => 'Cash to Win',
            self::WinToCash => 'Win to Cash',
        };
    }

    /**
     * The wallet the money leaves.
     */
    public function fromType(): WalletType
    {
        return match ($this) {
            self::CashToWin => WalletType::Primary,
            self::WinToCash => WalletType::Win,
        };
    }

    /**
     * The wallet the money arrives in.
     */
    public function toType(): WalletType
    {
        return match ($this) {
            self::CashToWin => WalletType::Win,
            self::WinToCash => WalletType::Primary,
        };
    }

    /**
     * The published fee row for this direction. The public fees page and the
     * engine resolve the SAME path — a second spelling would let the page and
     * the charge drift apart.
     */
    public function feeConfigPath(): string
    {
        return 'fees.categories.'.$this->value;
    }

    /**
     * Total function over the raw strings a request can carry. Unknown input
     * resolves to null so a typo is refused rather than silently priced as
     * the default direction.
     */
    public static function tryFromString(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    public function opposite(): self
    {
        return match ($this) {
            self::CashToWin => self::WinToCash,
            self::WinToCash => self::CashToWin,
        };
    }
}
