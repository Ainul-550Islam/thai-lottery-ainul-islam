<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Exceptions\LegacyLotteryEntryException;
use App\Models\LotteryEntry;

/**
 * Retained for callers and old queue payloads, but not a sales entry point.
 * LotteryEntry has no safe, provenance-checked settlement/migration path.
 * New sales must use the product-specific canonical purchase services; doing a
 * silent conversion here would change the purchased contract and its ledger.
 */
class OrderPlacementService
{
    public function placeOrder(
        int|string $userId,
        int|string $drawId,
        string $ticketNumber,
        string $betType,
        string $stakeAmount,
        int|string|null $agentId = null,
        ?string $idempotencyKey = null
    ): LotteryEntry {
        throw LegacyLotteryEntryException::unavailable('sales');
    }
}
