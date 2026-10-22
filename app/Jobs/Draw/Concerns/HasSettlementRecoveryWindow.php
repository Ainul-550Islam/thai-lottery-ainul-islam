<?php

declare(strict_types=1);

namespace App\Jobs\Draw\Concerns;

use DateTimeInterface;

/**
 * Overlap releases are not failed money executions. Laravel counts each
 * reservation as an attempt, including releases caused by an orphaned lock.
 * Persist a finite time budget in the queue payload instead of exhausting
 * tries while that lock is still alive. Each job separately bounds actual
 * exceptions with maxExceptions and retains its fail-on-timeout policy.
 */
trait HasSettlementRecoveryWindow
{
    public function retryUntil(): DateTimeInterface
    {
        // Laravel evaluates this when serializing a NEW delivery. Releasing
        // the existing payload does not extend the stored deadline.
        return now()->addMinutes(30);
    }
}
