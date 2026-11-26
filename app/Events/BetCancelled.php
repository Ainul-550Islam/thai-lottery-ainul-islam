<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Bet;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A bet was cancelled, AFTER the fact and AFTER the money moved back.
 *
 * WHY BATCH 2 INTRODUCES AN EVENT BATCH 1 DOES NOT DISPATCH ITSELF
 * Two digit-game obligations have to be undone when a bet is cancelled: the reserved
 * liability must be given back, and a cashback accrual (where the deployment has one) must
 * be reversed. Batch 1's cancellation path already unwinds its own money — it does not know
 * that a digit game reserved anything, and it must not be taught to. An event is the
 * seam that lets the lane react without editing the cancellation path (§0.1, §0.2).
 *
 * WHAT IT IS NOT
 * It is not a request to cancel anything, and it is not dispatched before the cancellation
 * commits. It is a statement of fact: the bet IS cancelled, the player's money is back, and
 * anything that was reserved against it should now be released. That ordering is why both
 * listeners catch their own failures and log them rather than throwing: the cancellation has
 * already happened, so an exception here would report a 500 for a request that succeeded and
 * roll nothing back.
 *
 * `reason` IS PART OF THE EVENT, NOT OF THE LISTENER
 * Both listeners record it (one in the log, one in the cashback reversal call) and an
 * operator investigating a balance needs to know WHY the bet came undone. Keeping it on the
 * event means every listener sees the same words.
 */
final class BetCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Bet $bet,
        public readonly string $reason,
    ) {}
}
