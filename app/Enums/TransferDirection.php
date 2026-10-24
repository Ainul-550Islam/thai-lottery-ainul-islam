<?php

declare(strict_types=1);

namespace App\Enums;

use App\Exceptions\BalanceTransferException;

/**
 * Supported player pocket-to-pocket balance transfer directions.
 */
enum TransferDirection: string
{
    case CashToWin = 'cash_to_win';
    case WinToCash = 'win_to_cash';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }

    public static function tryFromInput(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        $normalised = strtolower(trim($value));

        if ($normalised === '') {
            return null;
        }

        return self::tryFrom($normalised);
    }

    public static function betweenWalletTypes(WalletType $source, WalletType $destination): self
    {
        if (! $source->isTransferPocket() || ! $destination->isTransferPocket() || $source === $destination) {
            throw BalanceTransferException::invalidLane($source->value, $destination->value);
        }

        return match ([$source, $destination]) {
            [WalletType::Primary, WalletType::Win] => self::CashToWin,
            [WalletType::Win, WalletType::Primary] => self::WinToCash,
            default => throw BalanceTransferException::invalidLane($source->value, $destination->value),
        };
    }

    public function sourceWalletType(): WalletType
    {
        return match ($this) {
            self::CashToWin => WalletType::Primary,
            self::WinToCash => WalletType::Win,
        };
    }

    public function destinationWalletType(): WalletType
    {
        return match ($this) {
            self::CashToWin => WalletType::Win,
            self::WinToCash => WalletType::Primary,
        };
    }

    public function opposite(): self
    {
        return match ($this) {
            self::CashToWin => self::WinToCash,
            self::WinToCash => self::CashToWin,
        };
    }

    /**
     * @return array{from: string, to: string}
     */
    public function pocketNames(): array
    {
        return [
            'from' => (string) $this->sourceWalletType()->transferPocketName(),
            'to' => (string) $this->destinationWalletType()->transferPocketName(),
        ];
    }

    public function feeCategory(): string
    {
        if (function_exists('config')) {
            try {
                $configured = config('transfer.directions.'.$this->value.'.fee_category');
                if (is_string($configured) && trim($configured) !== '') {
                    return trim($configured);
                }
            } catch (\Throwable) {
                // Fall back to canonical case value when config container is not booted.
            }
        }

        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::CashToWin => 'Cash to Win',
            self::WinToCash => 'Win to Cash',
        };
    }
}
