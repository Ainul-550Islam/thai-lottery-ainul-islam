<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Dealer change-request workflow:
 *   submitted → under_review → approved | rejected
 *   approved → cancelled (optional, only when workflow requires it)
 *
 * Direct submitted → approved is forbidden (review must seat first).
 */
enum GloDealerRequestStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Cancelled], true);
    }

    public function allowsOperatorDecision(): bool
    {
        return $this === self::UnderReview;
    }
}
