<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * BetSlipStatus — the lifecycle of a member's draft basket.
 *
 * Draft      the slip is editable. It holds no money and reserves nothing.
 * Confirmed  every item was purchased through the existing betting pipeline
 *            and the slip is now historical. Terminal.
 * Expired    the TTL elapsed (or the underlying draw closed) before confirm.
 *            Terminal; the items were never bought.
 *
 * WHY THERE IS NO 'cancelled'
 * A member who wants a different slip clears it, which returns the row to an
 * empty draft rather than creating a status that has to be excluded from every
 * later query. A slip is not a contract, so cancelling one is just editing it.
 *
 * WHY THERE IS NO 'partially_confirmed'
 * Confirm is all-or-nothing (single transaction). A partial state would mean a
 * member paid for some selections and not others without asking for that, which
 * is precisely the outcome the batch forbids.
 */
enum BetSlipStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Expired = 'expired';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Confirmed',
            self::Expired => 'Expired',
        };
    }

    /**
     * Only a draft accepts new items or a confirm attempt.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether the slip can still be confirmed. Expiry by TIME is a separate
     * check performed against expires_at, because a row can still say 'draft'
     * while its clock has run out.
     */
    public function canBeConfirmed(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Terminal states are never transitioned out of.
     */
    public function isFinal(): bool
    {
        return $this !== self::Draft;
    }
}
