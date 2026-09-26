<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * GLO ticket freeze / seizure case lifecycle (GLO-11).
 *
 * This is a DEDICATED legal/administrative state machine. It is deliberately
 * separate from wallet.freeze, FinancialHold, withdrawal holds and the generic
 * operator PrizeClaim lane: those express money staging, not a GLO-style
 * ticket seizure backed by official evidence.
 *
 *   requested ──▶ under_review ──▶ frozen ──▶ released
 *        │              │            │
 *        │              │            └──▶ expired
 *        │              └──▶ rejected
 *        └──▶ rejected
 *   rejected ──▶ requested   (authorized reopen only)
 *
 * Forbidden without an explicit authorized transition: requested→frozen is
 * only through under_review (or direct reject); rejected→frozen; frozen→paid;
 * any terminal state mutated by ordinary review.
 */
enum GloFreezeStatus: string
{
    case Requested = 'requested';

    case UnderReview = 'under_review';

    case Frozen = 'frozen';

    case Released = 'released';

    case Rejected = 'rejected';

    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'REQUESTED',
            self::UnderReview => 'UNDER REVIEW',
            self::Frozen => 'FROZEN',
            self::Released => 'RELEASED',
            self::Rejected => 'REJECTED',
            self::Expired => 'EXPIRED',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Requested => 'blue',
            self::UnderReview => 'yellow',
            self::Frozen => 'danger',
            self::Released => 'green',
            self::Rejected => 'gray',
            self::Expired => 'gray',
        };
    }

    /**
     * Legal direct transitions out of this state.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::UnderReview, self::Rejected],
            self::UnderReview => [self::Frozen, self::Rejected],
            self::Frozen => [self::Released, self::Expired],
            self::Rejected => [self::Requested],
            self::Released => [],
            self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Released || $this === self::Expired;
    }

    /** Whether a case in this state blocks prize payment on its ticket. */
    public function blocksPayment(): bool
    {
        return $this === self::Frozen;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
