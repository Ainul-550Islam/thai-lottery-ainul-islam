<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Concerns\BroadcastsOnNotificationsLane;
use App\Models\Withdrawal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a withdrawal's status changes.
 *
 * Private, owner-scoped, after commit. The payload is the status fact and the reference the
 * player already holds - never the destination account, the provider payload or any fee
 * breakdown the withdrawal screen does not render live.
 */
final class WithdrawalStatusUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnNotificationsLane;
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Withdrawal $withdrawal,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.(int) $this->withdrawal->user_id),
            new PrivateChannel('user.'.(int) $this->withdrawal->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'withdrawal.status.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'reference_number' => (string) $this->withdrawal->reference_number,
            'status' => $this->withdrawal->status?->value,
            'amount' => (string) $this->withdrawal->amount,
            'currency' => $this->withdrawal->currency?->value,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
