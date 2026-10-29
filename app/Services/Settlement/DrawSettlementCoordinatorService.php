<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Exceptions\LegacyLotteryEntryException;

/**
 * Retired LotteryEntry coordinator. Canonical Bet settlement cannot be
 * substituted for legacy entries without an explicit financial migration.
 */
class DrawSettlementCoordinatorService
{
    public function initiateSettlement(int|string $drawId, int|string $initiatingOperatorId): int
    {
        throw LegacyLotteryEntryException::unavailable('settlement coordination');
    }
}
