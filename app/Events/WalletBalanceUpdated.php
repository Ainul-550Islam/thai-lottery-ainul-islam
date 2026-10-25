<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Concerns\BroadcastsOnNotificationsLane;
use App\Models\Wallet;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a player's wallet balance changes.
 *
 * THE ONE CANONICAL WALLET BROADCASTER
 * App\Events\WalletBalanceUpdatedEvent used to broadcast the same `wallet.balance.updated`
 * name for the same write, so a player with one subscription received two messages. This
 * class is the only one that broadcasts now; it is published by MoneyChainRealtimeObserver
 * from the wallet row itself, so one row write is one message regardless of how many
 * services took part in the write.
 *
 * PRIVATE, OWNER-SCOPED, AFTER COMMIT
 * Only private channels whose name carries the owner's id (authorized in routes/channels.php),
 * dispatched only once the surrounding transaction has committed, and queued on the
 * notifications lane. The payload is the balances and the wallet's own identity - never a
 * ledger row, a transaction id or another player's anything.
 */
final class WalletBalanceUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnNotificationsLane;
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Wallet $wallet,
        public readonly ?string $reason = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.(int) $this->wallet->user_id),
            new PrivateChannel('user.'.(int) $this->wallet->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'wallet.balance.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'wallet_id' => (int) $this->wallet->id,
            'wallet_type' => $this->wallet->type?->value,
            'currency' => $this->wallet->currency?->value,
            'balance' => (string) $this->wallet->balance,
            'locked_balance' => (string) $this->wallet->locked_balance,
            'available_balance' => (string) $this->wallet->getAvailableBalance(),
            'version' => (int) $this->wallet->version,
            'reason' => $this->reason,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
