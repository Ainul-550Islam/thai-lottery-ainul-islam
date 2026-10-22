<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Analytics\ConversionEvent;
use App\Services\Analytics\Ga4MeasurementProtocol;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Deliver one marketing conversion to GA4 (D3).
 *
 * RETRIES ARE ZERO, AND THAT IS A MEASUREMENT DECISION
 * ----------------------------------------------------
 * The instinct with a queued HTTP job is to retry a few times. Here that would
 * be wrong: a conversion carries money, so a job that fails *after* the request
 * reached GA4 but *before* the response came back would, on retry, send the
 * same transaction id twice and double-count revenue. A dropped conversion
 * under-reports by one; a duplicated one over-reports and there is no way to
 * tell the two apart later.
 *
 * Under-reporting is the safe direction.
 *
 * THIS JOB MUST NEVER THROW
 * -------------------------
 * A throw here triggers Laravel's retry logic, which is exactly what the
 * paragraph above rules out. So the body catches everything, and handle()
 * always returns cleanly. The transport is already failure-isolated; this is
 * the second layer, because "the transport will not throw" is a promise about
 * someone else's code.
 *
 * THE QUEUE IS THE LOWEST PRIORITY ONE
 * ------------------------------------
 * QueueName::Analytics is deliberately last in the priority order. If this
 * queue backs up, the product is unaffected and the numbers arrive late.
 */
class SendConversionEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * No retries: a duplicated money event is worse than a missing one.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Give up rather than linger. A stuck analytics job should not occupy a
     * worker while financial work waits behind it.
     *
     * @var int
     */
    public $timeout = 20;

    /**
     * @param  array<string, string>  $identity  'client_id' and/or 'session_id'
     */
    public function __construct(
        public readonly ConversionEvent $event,
        public readonly array $identity = [],
    ) {}

    public function handle(Ga4MeasurementProtocol $transport): void
    {
        try {
            $transport->send($this->event, $this->identity);
        } catch (Throwable $e) {
            // Swallowed deliberately. See the class docblock: a throw here
            // would trigger a retry, and a retry risks double-counting money.
            // Nothing downstream depends on this having succeeded.
            unset($e);
        }
    }

    /**
     * A failed analytics job must not reach the failed-jobs table. That table
     * is for work that a human has to fix; a dropped conversion is not, and
     * filling it with analytics noise would bury the jobs that are.
     */
    public function failed(?Throwable $exception): void
    {
        // Intentionally empty.
    }
}
