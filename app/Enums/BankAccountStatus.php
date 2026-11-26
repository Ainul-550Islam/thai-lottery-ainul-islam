<?php

declare(strict_types=1);

namespace App\Enums;

enum BankAccountStatus: string
{
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::PendingVerification => 'Pending Verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Disabled => 'Disabled',
        };
    }

    public function isUsableForWithdrawal(): bool
    {
        return $this === self::Verified;
    }
}
