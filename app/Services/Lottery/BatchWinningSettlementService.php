<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Exceptions\LegacyLotteryEntryException;
use App\Models\DrawResult;

/** Legacy LotteryEntry amounts must not bypass canonical payout provenance. */
class BatchWinningSettlementService
{
    /** @return array{total_processed: int, total_winners: int, total_payout_thb: string} */
    public function settleDrawEntries(DrawResult $drawResult, int $chunkSize = 1000): array
    {
        throw LegacyLotteryEntryException::unavailable('batch settlement');
    }
}
