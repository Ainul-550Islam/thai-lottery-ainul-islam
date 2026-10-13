<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use App\Enums\Currency;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionRejectionReason;
use App\Enums\WalletType;
use Throwable;

/**
 * The source wallet cannot cover the amount being converted.
 *
 * WHY THIS DOES NOT REUSE InsufficientBalanceException
 * The sibling exception reports "wallet 41 has 100.00, you asked for 250.00" —
 * enough for a bet or a withdrawal, where the only possible remedy is to add
 * money. A conversion has a second remedy and a second wallet: the member may
 * simply be looking at the wrong direction, having meant to move winnings from
 * Win rather than Cash. This exception therefore also carries the direction,
 * the source wallet type, and — when the service knows it — the available
 * balance of the OPPOSITE wallet, so the UI can say "you have 250.00 in Win;
 * did you mean Win to Cash?" instead of a dead end.
 *
 * The comparison itself is NOT performed here. The service compares exact
 * decimals while holding a row lock on the source wallet; an exception that
 * recomputed a balance would read state outside that lock and could report a
 * number that never existed. Amounts are carried as decimal strings, never
 * floats, so the reported shortfall matches the ledger to the cent.
 */
final class ConversionInsufficientBalanceException extends ConversionException
{
    public const ERROR_CODE = WalletConversionRejectionReason::InsufficientBalance->value;

    public function __construct(
        private readonly int $sourceWalletId,
        private readonly WalletType $sourceWalletType,
        private readonly WalletConversionDirection $direction,
        private readonly string $requestedAmount,
        private readonly string $availableAmount,
        private readonly Currency $currency,
        private readonly ?string $oppositeWalletBalance = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'The %s wallet (%d) has %s %s available, which is less than the %s %s needed for this %s conversion.',
                $sourceWalletType->value,
                $sourceWalletId,
                $currency->value,
                $availableAmount,
                $currency->value,
                $requestedAmount,
                $direction->value,
            ),
            self::ERROR_CODE,
            [
                'source_wallet_id' => $sourceWalletId,
                'source_wallet_type' => $sourceWalletType->value,
                'direction' => $direction->value,
                'currency' => $currency->value,
                'requested_amount' => $requestedAmount,
                'available_amount' => $availableAmount,
                'shortfall_amount' => bcsub($requestedAmount, $availableAmount, 2),
                'opposite_wallet_balance' => $oppositeWalletBalance,
            ],
            $previous,
        );
    }

    /**
     * @param  string  $oppositeWalletBalance  the destination-side wallet's
     *                                         available balance, when the
     *                                         service has already read it
     */
    public static function forAmount(
        int $sourceWalletId,
        WalletType $sourceWalletType,
        WalletConversionDirection $direction,
        string $requestedAmount,
        string $availableAmount,
        Currency $currency,
        ?string $oppositeWalletBalance = null,
        ?Throwable $previous = null,
    ): self {
        return new self(
            $sourceWalletId,
            $sourceWalletType,
            $direction,
            $requestedAmount,
            $availableAmount,
            $currency,
            $oppositeWalletBalance,
            $previous,
        );
    }

    public function sourceWalletId(): int
    {
        return $this->sourceWalletId;
    }

    public function sourceWalletType(): WalletType
    {
        return $this->sourceWalletType;
    }

    public function direction(): WalletConversionDirection
    {
        return $this->direction;
    }

    public function requestedAmount(): string
    {
        return $this->requestedAmount;
    }

    public function availableAmount(): string
    {
        return $this->availableAmount;
    }

    public function shortfallAmount(): string
    {
        return bcsub($this->requestedAmount, $this->availableAmount, 2);
    }

    public function oppositeWalletBalance(): ?string
    {
        return $this->oppositeWalletBalance;
    }

    /**
     * True when the opposite wallet alone would have covered the request — the
     * signal the UI uses to offer the other direction instead of a dead end.
     */
    public function oppositeWalletWouldSuffice(): bool
    {
        return $this->oppositeWalletBalance !== null
            && bccomp($this->oppositeWalletBalance, $this->requestedAmount, 2) !== -1;
    }

    public function rejectionReason(): ?WalletConversionRejectionReason
    {
        return WalletConversionRejectionReason::InsufficientBalance;
    }
}
