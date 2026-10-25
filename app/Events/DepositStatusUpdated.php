<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Concerns\BroadcastsOnNotificationsLane;
use App\Models\Deposit;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a deposit's status changes.
 *
 * Private, owner-scoped, after commit. The payload is the status fact and the reference the
 * player already holds - never the provider payload, the evidence or the failure detail.
 */
final class DepositStatusUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnNotificationsLane;
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Deposit $deposit,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.(int) $this->deposit->user_id),
            new PrivateChannel('user.'.(int) $this->deposit->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'deposit.status.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'reference_number' => (string) $this->deposit->reference_number,
            'status' => $this->deposit->status?->value,
            'amount' => (string) $this->deposit->amount,
            'currency' => $this->deposit->currency?->value,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
