<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Concerns\BroadcastsOnNotificationsLane;
use App\Models\Bet;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a bet has been successfully placed.
 *
 * Private, owner-scoped, after commit. The payload is the ticket reference, the draw it is on,
 * its status and the stake - the four facts the bet slip confirms - and nothing about any other
 * player or the odds book.
 */
final class BetPlaced implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnNotificationsLane;
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Bet $bet,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.(int) $this->bet->user_id),
            new PrivateChannel('user.'.(int) $this->bet->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'bet.placed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'reference_number' => (string) $this->bet->bet_number,
            'ticket_id' => $this->bet->ticket_id !== null ? (int) $this->bet->ticket_id : null,
            'draw_id' => (int) $this->bet->draw_id,
            'status' => $this->bet->status?->value,
            'total_stake' => (string) $this->bet->stake_amount,
            'currency' => $this->bet->currency?->value,
            'placed_at' => $this->bet->placed_at?->toIso8601String(),
        ];
    }
}
