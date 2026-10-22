<?php

declare(strict_types=1);

namespace App\Listeners\Analytics;

use App\Enums\QueueName;
use App\Events\WithdrawalStatusUpdated;
use App\Services\Analytics\ConversionTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Record a withdrawal request as a marketing event (D3).
 *
 * WHY MEASURE MONEY GOING OUT
 * Because the ratio of deposits to withdrawals is the clearest early signal of
 * a problem: a channel that brings players who deposit once and withdraw
 * immediately is not the same business as one that brings players who stay.
 * Without this event the funnel simply stops at the deposit and the difference
 * is invisible.
 *
 * THIS IS A REQUEST, NOT A PAYOUT
 * The event fires when the withdrawal is requested. It is deliberately NOT
 * tied to the payout completing, because the player's decision — the thing
 * worth measuring — has already been made at request time.
 */
final class TrackWithdrawalConversion implements ShouldQueue
{
    /** @var int */
    public $tries = 1;

    public function __construct(
        private readonly ConversionTracker $tracker,
    ) {}

    public function viaQueue(): string
    {
        return QueueName::Analytics->value;
    }

    public function handle(WithdrawalStatusUpdated $event): void
    {
        try {
            $withdrawal = $event->withdrawal;

            $this->tracker->withdrawalRequested(
                amount: (float) $withdrawal->amount,
                currency: (string) $withdrawal->currency,
                reference: (string) $withdrawal->reference,
                method: (string) $withdrawal->method,
                playerRef: $this->tracker->playerRef($withdrawal->user_id),
            );
        } catch (Throwable) {
            // Swallowed: see the job docblock.
        }
    }
}
