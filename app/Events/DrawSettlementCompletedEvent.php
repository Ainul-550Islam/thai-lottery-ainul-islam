<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DrawSettlementCompletedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $drawId,
        public readonly string $drawCode,
        public readonly string $gameType,
        public readonly string $firstPrize,
        public readonly string $totalPayoutAmount,
        public readonly int $totalWinnersCount
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("draws.{$this->drawId}"),
            new Channel('draws.live'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'draw.settlement.completed';
    }

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
