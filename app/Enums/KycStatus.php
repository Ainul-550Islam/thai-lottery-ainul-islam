<?php

declare(strict_types=1);

namespace App\Enums;

enum KycStatus: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';

    // Backward-compatible aliases for older callers; the canonical cases remain above.
    public const UNVERIFIED = self::Unverified;
    public const PENDING = self::Pending;
    public const VERIFIED = self::Verified;
    public const REJECTED = self::Rejected;
    public const EXPIRED = self::Expired;

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }

    public function canSubmit(): bool
    {
        return in_array($this, [self::Unverified, self::Rejected, self::Expired], true);
    }
}
