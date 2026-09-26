<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * GLO prize-claim lifecycle (GLO-12) — distinct from the operator's generic
 * PrizeClaimStatus, which rides on digital-bet payouts. A GLO claim is the
 * claimant's assertion over an OFFICIAL GLO ticket prize and is gated by
 * freeze state, age, identity and stamp duty.
 *
 *   pending ──▶ eligible ──▶ approved ──▶ paid
 *      │            │            │
 *      │            │            └──▶ hold   (freeze appears before pay)
 *      │            └──▶ hold
 *      ├──▶ hold
 *      ├──▶ rejected
 *      └──▶ cancelled
 *   hold ──▶ eligible   (freeze released / expired per configured transition)
 *   hold ──▶ rejected | cancelled
 *   approved ──▶ hold | rejected (only before money moves)
 *
 * paid, rejected and cancelled are terminal. under-age can never reach
 * approved or paid; payment_hold can never become paid without hold clearing.
 */
enum GloClaimStatus: string
{
    case Pending = 'pending';

    case Eligible = 'eligible';

    case Hold = 'hold';

    case Approved = 'approved';

    case Paid = 'paid';

    case Rejected = 'rejected';

    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'PENDING',
            self::Eligible => 'ELIGIBLE',
            self::Hold => 'PAYMENT HOLD',
            self::Approved => 'APPROVED',
            self::Paid => 'PAID',
            self::Rejected => 'REJECTED',
            self::Cancelled => 'CANCELLED',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'blue',
            self::Eligible => 'cyan',
            self::Hold => 'warning',
            self::Approved => 'green',
            self::Paid => 'success',
            self::Rejected => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Eligible, self::Hold, self::Rejected, self::Cancelled],
            self::Eligible => [self::Hold, self::Approved, self::Rejected, self::Cancelled],
            self::Hold => [self::Eligible, self::Rejected, self::Cancelled],
            self::Approved => [self::Paid, self::Hold],
            self::Paid, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Paid || $this === self::Rejected || $this === self::Cancelled;
    }

    public function locksPayment(): bool
    {
        return $this === self::Hold || $this === self::Pending;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
