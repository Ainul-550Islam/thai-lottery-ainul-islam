<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Class WalletBalanceUpdatedEvent
 *
 * Broadcasts instantaneous balance mutations directly through Laravel Reverb / Soketi WebSockets.
 */
class WalletBalanceUpdatedEvent implements ShouldBroadcastNow
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

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->userId}.wallet"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'wallet.balance.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
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
