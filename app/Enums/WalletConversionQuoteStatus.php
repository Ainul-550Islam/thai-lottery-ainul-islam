<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of a priced quote.
 *
 * A quote is a promise with an expiry: the numbers a member was shown, frozen,
 * executable until `expires_at` and then not. The states are therefore about
 * whether the promise can still be spent, not about money — no state here means
 * a conversion has happened.
 *
 *   Open     — the promise stands: not consumed, not yet past its clock. This is
 *              the state a new quote is written in and the only state the
 *              execute path will accept. The stored value is 'open' because the
 *              sweep, the unique quote-slot index and the operator screens all
 *              read quotes by that literal.
 *   Consumed — the promise was spent by exactly one conversion, which is named
 *              by `converted_id`. Consuming is final: a second execution against
 *              the same quote is a second conversion for one price, which is the
 *              one thing the quote row exists to make impossible.
 *   Expired  — the clock ran out before anybody spent it. Labelled exactly once
 *              by the sweep; an expired quote is never revived, because the
 *              price it froze is no longer a price the platform offers.
 */
enum WalletConversionQuoteStatus: string
{
    case Open = 'open';
    case Consumed = 'consumed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Consumed => 'Consumed',
            self::Expired => 'Expired',
        };
    }

    /**
     * Whether this quote can still be executed against.
     */
    public function isSpendable(): bool
    {
        return $this === self::Open;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Consumed, self::Expired],
            self::Consumed, self::Expired => [],
        };
    }
}
