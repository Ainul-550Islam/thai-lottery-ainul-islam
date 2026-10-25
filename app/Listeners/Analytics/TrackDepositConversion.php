<?php

declare(strict_types=1);

namespace App\Listeners\Analytics;

use App\Enums\DepositStatus;
use App\Enums\QueueName;
use App\Events\DepositStatusUpdated;
use App\Services\Analytics\ConversionTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Record a deposit as a marketing conversion (D3).
 *
 * WHY A QUEUED LISTENER RATHER THAN A CALL IN THE DEPOSIT SERVICE
 * ---------------------------------------------------------------
 * The deposit path is money-critical and transactional. Calling an analytics
 * endpoint from inside it would put a third-party network call in the same
 * transaction as a wallet credit: if Google were slow, the deposit would be
 * slow, and if this code threw, the deposit would roll back. Measuring an
 * event must never be able to prevent the event.
 *
 * Listening to DepositStatusUpdated instead keeps the two completely separate.
 * The event fires, this listener is queued on the lowest-priority queue, and
 * the deposit has already committed.
 *
 * ONLY A SUCCESSFUL DEPOSIT COUNTS
 * --------------------------------
 * `isSuccessful()` is the domain's own definition, not one invented here. A
 * pending or rejected deposit is not revenue, and counting it would inflate
 * every acquisition report — which is worse than having no report, because it
 * would be believed.
 *
 * This class must never throw: see the job for why a throw here is worse than
 * a dropped conversion.
 */
final class TrackDepositConversion implements ShouldQueue
{
    /** @var int */
    public $tries = 1;

    public function __construct(
        private readonly ConversionTracker $tracker,
    ) {}

    /**
     * The notifications lane is the lowest-priority queue QueueName declares and every
     * worker consumes. This listener referenced a `QueueName::Analytics` case that does
     * not exist; while BetPlaced / DepositStatusUpdated / WithdrawalStatusUpdated were
     * never dispatched that was invisible, and the moment the money chain started
     * publishing it became a fatal error inside the save that moved the money.
     */
    public function viaQueue(): string
    {
        return QueueName::Notifications->value;
    }

    public function handle(DepositStatusUpdated $event): void
    {
        try {
            $deposit = $event->deposit;

            if (! $deposit->status?->isSuccessful()) {
                return;
            }

            // First deposit is the single most valuable number in the funnel,
            // so it is detected rather than assumed. The count is capped at 2
            // because we only need to know whether this is the first one.
            $first = ! $deposit->user
                ->deposits()
                ->whereKeyNot($deposit->getKey())
                ->whereIn('status', [DepositStatus::Confirmed->value])
                ->exists();

            $this->tracker->deposit(
                amount: (float) $deposit->amount,
                currency: (string) $deposit->currency,
                reference: (string) $deposit->reference,
                method: (string) $deposit->method,
                first: $first,
                playerRef: $this->tracker->playerRef($deposit->user_id),
            );
        } catch (Throwable) {
            // Swallowed. A missing relation or an unexpected model shape must
            // not produce a failed job, and must never surface to the player.
        }
    }
}
