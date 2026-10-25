<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Class WalletBalanceUpdatedEvent
 *
 * The scalar wallet-mutation event raised by App\Services\Broadcast\RealtimeSyncService after a
 * ledger-backed balance move. It is an in-process domain event for listeners that want the
 * mutation facts (type, version, reason) without loading the row.
 *
 * IT DOES NOT BROADCAST. The same write already reaches the player's browser through
 * App\Events\WalletBalanceUpdated, which MoneyChainRealtimeObserver publishes from the wallet
 * row under the very same `wallet.balance.updated` name. When this class also implemented
 * ShouldBroadcastNow the player received two identical messages per write (and the second one
 * left before the transaction committed). Delegating the wire to the row observer keeps the
 * public contract - one event name, one private channel per owner - while this class keeps its
 * constructor and its dispatch sites unchanged.
 *
 * The channel/payload accessors are kept for in-process consumers that already read them.
 */
class WalletBalanceUpdatedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $userId,
        public readonly string $walletType,
        public readonly string $currency,
        public readonly string $balance,
        public readonly string $availableBalance,
        public readonly int $version,
        public readonly string $reason
    ) {}

    /**
     * The owner-scoped channel this mutation belongs to (informational; see class docblock).
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->userId.'.wallet'),
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
            'wallet_type' => $this->walletType,
            'currency' => $this->currency,
            'balance' => $this->balance,
            'available_balance' => $this->availableBalance,
            'version' => $this->version,
            'reason' => $this->reason,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
