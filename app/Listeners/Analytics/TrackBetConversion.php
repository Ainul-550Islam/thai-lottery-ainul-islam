<?php

declare(strict_types=1);

namespace App\Listeners\Analytics;

use App\Enums\QueueName;
use App\Events\BetPlaced;
use App\Services\Analytics\ConversionTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Record an accepted bet as a marketing conversion (D3).
 *
 * A bet is the product, so it is the conversion every acquisition channel
 * should ultimately be judged against. It is measured here rather than in the
 * betting service for the same reason as the deposit listener: the purchase
 * path is transactional and must not acquire a third-party network dependency.
 *
 * NOTE ON THE AMOUNT
 * The stake is what the player risked, which is the correct `value` for a
 * wagering conversion. It is not revenue — recording gross stake as revenue
 * would overstate the business by a multiple of the house edge, and every
 * channel report would then rank affiliates by the wrong number.
 */
final class TrackBetConversion implements ShouldQueue
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

    public function handle(BetPlaced $event): void
    {
        try {
            $bet = $event->bet;

            $this->tracker->betPlaced(
                stake: (float) $bet->stake_amount,
                currency: (string) $bet->currency,
                reference: (string) $bet->reference,
                lane: $this->lane($bet),
                playerRef: $this->tracker->playerRef($bet->user_id),
            );
        } catch (Throwable) {
            // Swallowed: see the job docblock. A dropped conversion is a gap in
            // a report; a thrown exception is a failed job and a noisy queue.
        }
    }

    /**
     * Which lottery lane this bet belongs to.
     *
     * The lane is what makes the report useful — a national-lottery player and
     * a bingo-lottery player behave completely differently, and merging them
     * hides both. Resolved defensively because the relation chain differs
     * between lanes; an unresolvable lane drops the dimension, not the event.
     */
    private function lane(object $bet): string
    {
        foreach (['market', 'draw', 'game'] as $relation) {
            if (! isset($bet->$relation)) {
                continue;
            }

            $related = $bet->$relation;

            foreach (['lane', 'slug', 'code', 'type'] as $attribute) {
                $value = $related->$attribute ?? null;
                if (is_string($value) && $value !== '') {
                    return strtolower($value);
                }
            }
        }

        return '';
    }
}
