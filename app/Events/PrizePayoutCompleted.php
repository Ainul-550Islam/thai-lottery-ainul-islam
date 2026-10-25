<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Concerns\BroadcastsOnNotificationsLane;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a prize payout has fully completed: the payout row is
 * Completed, the linked financial transaction posted its balanced ledger
 * entries, and the winner's wallet shows the credited balance.
 *
 * LISTENERS DECIDE NOTHING
 * The event is dispatched only after the settling transaction has committed
 * (ShouldDispatchAfterCommit), so a payout that rolls back publishes nothing
 * and a listener never sees money that did not land. A listener must treat it
 * as a notification (emails, SMS, push, dashboards, reporting projections)
 * and may never attempt to mutate the payout, the wallet or the ledger from
 * within the event handler — the monetary truth is already fixed at dispatch
 * time.
 *
 * PRIVATE PAYLOAD DISCIPLINE
 * The winner's own private channel only; routes/channels.php refuses every
 * other subscriber. The payload contains only what the winner's own surface
 * needs. It never includes ledger ids, internal account codes, the payee
 * wallet id, the correlation id of the paying run or anyone else's ticket
 * details. (This event once used a public channel named after the winner;
 * any browser could subscribe to it and read the payout.)
 */
final class PrizePayoutCompleted implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnNotificationsLane;
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  string  $correlationId  The execution-context correlation id
     *                                 under which the payout landed, so a
     *                                 listener can join its own logs/audit
     *                                 onto the paying run without guessing.
     *                                 Server-side only: never broadcast.
     */
    public function __construct(
        public readonly Payout $payout,
        public readonly User $winner,
        public readonly string $correlationId,
    ) {}

    /**
     * The winner's private channel only: a prize is the player's own money,
     * not draw-level trivia for everyone else to see.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.(int) $this->winner->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'payout.completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'payout_id' => (int) $this->payout->id,
            'reference_number' => (string) $this->payout->reference_number,
            'draw_id' => (int) $this->payout->draw_id,
            'amount' => (string) $this->payout->amount,
            'currency' => $this->payout->currency->value,
            'paid_at' => $this->payout->processed_at?->toIso8601String(),
        ];
    }
}
