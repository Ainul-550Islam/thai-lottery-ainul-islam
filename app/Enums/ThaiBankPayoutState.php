<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\WithdrawalStatus;

enum ThaiBankPayoutState: string
{
    case Prepared = 'prepared';
    case Accepted = 'accepted';
    case Settled = 'settled';
    case Rejected = 'rejected';
    case Uncertain = 'uncertain';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Settled, self::Rejected], true);
    }

    public function mapsToWithdrawal(): ?WithdrawalStatus
    {
        return match ($this) {
            self::Prepared, self::Accepted, self::Uncertain => WithdrawalStatus::Processing,
            self::Settled => WithdrawalStatus::Completed,
            self::Rejected => WithdrawalStatus::Failed,
        };
    }
}
