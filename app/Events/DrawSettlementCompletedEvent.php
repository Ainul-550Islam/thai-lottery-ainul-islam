<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Concerns\BroadcastsOnNotificationsLane;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a draw's settlement run has finished.
 *
 * PUBLIC AGGREGATE ONLY. Rides the public `draws.live` / `draws.{id}` channels, so the payload is
 * the draw's identity and its totals (winner count, payout total, first prize) - never a per-user
 * figure, a wallet or a ticket. Dispatched after the settling transaction commits and queued on
 * the notifications lane like every other real-time hop, rather than pushed inline from the
 * settlement worker (ShouldBroadcastNow) where a slow socket server would have stalled money work.
 */
class DrawSettlementCompletedEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnNotificationsLane;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $drawId,
        public readonly string $drawCode,
        public readonly string $gameType,
        public readonly string $firstPrize,
        public readonly string $totalPayoutAmount,
        public readonly int $totalWinnersCount
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('draws.'.$this->drawId),
            new Channel('draws.live'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'draw.settlement.completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'draw_id' => $this->drawId,
            'draw_code' => $this->drawCode,
            'game_type' => $this->gameType,
            'first_prize' => $this->firstPrize,
            'total_payout_amount' => $this->totalPayoutAmount,
            'total_winners_count' => $this->totalWinnersCount,
            'settled_at' => now()->toIso8601String(),
        ];
    }
}
