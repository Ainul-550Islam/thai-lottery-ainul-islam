<?php

declare(strict_types=1);

namespace App\Enums;

enum SlipVerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Credited = 'credited';
    case NeedsReview = 'needs_review';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';
    case Expired = 'expired';
}
