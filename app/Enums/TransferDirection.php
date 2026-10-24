<?php

declare(strict_types=1);

namespace App\Enums;

enum TransferDirection: string
{
    case CashToWin = 'cash_to_win';
    case WinToCash = 'win_to_cash';

    public function sourcePocket(): string
    {
        return match ($this) {
            self::CashToWin => 'cash',
            self::WinToCash => 'win',
        };
    }

    public function destinationPocket(): string
    {
        return match ($this) {
            self::CashToWin => 'win',
            self::WinToCash => 'cash',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CashToWin => 'Cash to Win',
            self::WinToCash => 'Win to Cash',
        };
    }
}
