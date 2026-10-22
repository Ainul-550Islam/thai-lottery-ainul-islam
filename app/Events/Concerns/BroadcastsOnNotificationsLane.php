<?php

declare(strict_types=1);

namespace App\Events\Concerns;

use App\Enums\QueueName;

/**
 * Puts a broadcast on the notifications lane, and only after the commit that moved the money.
 *
 * Two decisions, one for each of the ways this chain used to be wrong. A broadcast queued on `default`
 * waits behind - and in front of - everything else, so a player's balance ping was held up by settlement
 * work while the queue drained; and a broadcast pushed inside the transaction that wrote the row is a
 * lie if that transaction rolls back, which is why the client saw numbers the ledger never carried.
 * Laravel reads both knobs off the event: BroadcastManager::queue() takes broadcastQueue() and the
 * BroadcastEvent job takes the afterCommit property, so this is where they belong rather than in six
 * copies of the same two lines.
 */
trait BroadcastsOnNotificationsLane
{
    /** @var bool Do not publish until the surrounding transaction commits; a rolled-back write has nothing to say. */
    public bool $afterCommit = true;

    public function broadcastQueue(): string
    {
        return QueueName::Notifications->value;
    }
}
