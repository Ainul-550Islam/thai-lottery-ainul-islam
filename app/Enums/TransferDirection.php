<?php

declare(strict_types=1);

namespace App\Enums;

use App\Exceptions\BalanceTransferException;

/**
 * The two lanes of the Cash <-> Win balance-transfer feature.
 *
 * ONE ENUM, ONE VOCABULARY
 * A direction is not just a label: it names the source and destination wallet
 * types, the pocket keys those wallets resolve to, the published fee category the
 * lane is priced from, and the inverse lane. Every one of those facts is derived
 * here rather than re-implemented by callers, because a caller that derives a
 * direction locally is a second source of truth that will eventually move money
 * the wrong way.
 *
 * This file was previously cut down to three members (sourcePocket,
 * destinationPocket, label). BalanceTransferService, TransferFeeCalculator,
 * TransferPanelService, TransferPanelService, BalanceTransfer::toPlayerArray(),
 * BalanceTransferController, StoreBalanceTransferRequest and
 * BalanceTransferFactory all call the members below, so every transfer endpoint
 * failed with "Call to undefined method" before it could reach business logic.
 * The contract is pinned by tests/Unit/Transfer/TransferDirectionTest.php.
 */
enum TransferDirection: string
{
    case CashToWin = 'cash_to_win';
    case WinToCash = 'win_to_cash';

    /**
     * The wallet the money leaves.
     */
    public function sourceWalletType(): WalletType
    {
        return match ($this) {
            self::CashToWin => WalletType::Primary,
            self::WinToCash => WalletType::Win,
        };
    }

    /**
     * The wallet the money arrives in.
     */
    public function destinationWalletType(): WalletType
    {
        return match ($this) {
            self::CashToWin => WalletType::Win,
            self::WinToCash => WalletType::Primary,
        };
    }

    /**
     * The pocket key the source wallet resolves to ('cash' | 'win').
     */
    public function sourcePocket(): string
    {
        return $this->sourceWalletType()->transferPocketName()
            ?? throw BalanceTransferException::invalidLane($this->sourceWalletType()->value, 'cash_or_win');
    }

    /**
     * The pocket key the destination wallet resolves to ('cash' | 'win').
     */
    public function destinationPocket(): string
    {
        return $this->destinationWalletType()->transferPocketName()
            ?? throw BalanceTransferException::invalidLane($this->destinationWalletType()->value, 'cash_or_win');
    }

    /**
     * The pocket key pair, in the shape the transfer panel and history render.
     *
     * @return array{from: string, to: string}
     */
    public function pocketNames(): array
    {
        return [
            'from' => $this->sourcePocket(),
            'to' => $this->destinationPocket(),
        ];
    }

    /**
     * The lane that moves the money back.
     *
     * A reversal is not "the same direction, negative amount": it is the opposite
     * lane, and it is priced as such.
     */
    public function opposite(): self
    {
        return match ($this) {
            self::CashToWin => self::WinToCash,
            self::WinToCash => self::CashToWin,
        };
    }

    /**
     * The published fee category this direction is priced from.
     *
     * The value is a row in config/fees.php and on the public /fees page, so the
     * number a player reads and the number the engine charges come from one source.
     * config/transfer.php declares the mapping per direction; the enum value is the
     * fallback, which is what the shipped config uses for both lanes.
     */
    public function feeCategory(): string
    {
        $configured = config('transfer.directions.'.$this->value.'.fee_category');

        return is_string($configured) && $configured !== '' ? $configured : $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::CashToWin => 'Cash to Win',
            self::WinToCash => 'Win to Cash',
        };
    }

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

    /**
     * Exact, case-insensitive lookup of a request value.
     *
     * Anything else returns null - never a default and never a partial match, so a
     * caller cannot turn `?direction=whatever` into a real money movement.
     */
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

    /**
     * The lane between two wallet types.
     *
     * Refuses rather than guesses: a same-type request is a wallet transferring to
     * itself, and a non-pocket type (bonus, commission) is not part of this feature
     * — treating either as Cash-to-Win would move value through a lane the player
     * never chose.
     *
     * @throws BalanceTransferException
     */
    public static function betweenWalletTypes(WalletType $from, WalletType $to): self
    {
        foreach (self::cases() as $direction) {
            if ($direction->sourceWalletType() === $from && $direction->destinationWalletType() === $to) {
                return $direction;
            }
        }

        throw BalanceTransferException::invalidLane($from->value, $to->value);
    }
}
