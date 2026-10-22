<?php

declare(strict_types=1);

namespace App\Services\Broadcast;

use App\Events\DrawSettlementCompletedEvent;
use App\Events\WalletBalanceUpdatedEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Class RealtimeSyncService
 *
 * Coordinates real-time notifications, WebSocket broadcasts (Laravel Reverb/Soketi),
 * and high-throughput Redis Pub/Sub channels.
 */
class RealtimeSyncService
{
    /**
     * Dispatch atomic wallet balance update event to user WebSocket channel.
     */
    public function syncWalletBalance(
        string $userId,
        string $walletType,
        string $currency,
        string $balance,
        string $availableBalance,
        int $version,
        string $reason
    ): void {
        event(new WalletBalanceUpdatedEvent(
            userId: $userId,
            walletType: $walletType,
            currency: $currency,
            balance: $balance,
            availableBalance: $availableBalance,
            version: $version,
            reason: $reason
        ));

        // Publish to internal Redis Pub/Sub cluster when available without aborting committed ledger mutations.
        if (config('database.redis.client') === 'phpredis' && ! class_exists(\Redis::class)) {
            return;
        }

        try {
            Redis::publish("events:user:{$userId}:wallet", json_encode([
                'balance' => $balance,
                'available' => $availableBalance,
                'version' => $version,
                'updated_at' => microtime(true),
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            Log::warning('realtime_sync.redis_publish_failed', [
                'channel' => "events:user:{$userId}:wallet",
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Broadcast draw settlement event to public lottery room channels.
     */
    public function broadcastDrawSettlement(
        string $drawId,
        string $drawCode,
        string $gameType,
        string $firstPrize,
        string $totalPayoutAmount,
        int $totalWinnersCount
    ): void {
        event(new DrawSettlementCompletedEvent(
            drawId: $drawId,
            drawCode: $drawCode,
            gameType: $gameType,
            firstPrize: $firstPrize,
            totalPayoutAmount: $totalPayoutAmount,
            totalWinnersCount: $totalWinnersCount
        ));
    }
}
