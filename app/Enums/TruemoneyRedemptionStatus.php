<?php

declare(strict_types=1);

namespace App\Enums;

enum TruemoneyRedemptionStatus: string
{
    case Pending = 'pending';
    case Redeemed = 'redeemed';
    case Credited = 'credited';
    case Rejected = 'rejected';
    case RedeemedUnconfirmed = 'redeemed_unconfirmed';
}
