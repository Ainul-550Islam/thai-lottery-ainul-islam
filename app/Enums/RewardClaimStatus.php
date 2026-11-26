<?php

declare(strict_types=1);

namespace App\Enums;

enum RewardClaimStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Reversed => 'Reversed',
        };
    }

    public function countsAgainstMemberLimit(): bool
    {
        return in_array($this, [self::Pending, self::Completed], true);
    }
}
