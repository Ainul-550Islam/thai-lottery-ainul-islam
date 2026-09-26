<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Public-safe ticket status vocabulary (GLO-13).
 *
 * Only these codes may leave the public verification endpoint. Anything else
 * (claimant identity, evidence, staff notes, internal ids beyond the public
 * reference) is filtered out in GloPublicTicketVerificationService.
 */
enum GloPublicStatus: string
{
    case NotFound = 'NOT_FOUND';

    case NotFrozen = 'NOT_FROZEN';

    case Frozen = 'FROZEN';

    case FrozenWinningPaymentHeld = 'FROZEN_AND_WINNING_PAYMENT_HELD';

    case Released = 'RELEASED';

    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::NotFound => 'Not found',
            self::NotFrozen => 'Not frozen',
            self::Frozen => 'Frozen',
            self::FrozenWinningPaymentHeld => 'Frozen — winning prize payment held',
            self::Released => 'Released',
            self::Expired => 'Expired',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
