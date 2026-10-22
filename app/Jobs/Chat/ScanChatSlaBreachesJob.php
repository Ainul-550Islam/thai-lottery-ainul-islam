<?php

declare(strict_types=1);

namespace App\Jobs\Chat;

use App\Enums\QueueName;
use App\Services\Chat\ChatSlaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled sweep: raise first-response SLA breaches exactly once each.
 *
 * ============================================================================
 * THE JOB IS A TRIGGER; THE TRAIL IS THE STATE
 * ============================================================================
 * ChatSlaService::scanForBreaches() finds waiting conversations past the
 * response-warning threshold and records a sla_breached audit event for any
 * that does not already have one. "Already raised" is therefore read from
 * the audit trail, which is what makes this job safe to run on any cadence:
 * a breach is raised once and only once no matter how many sweeps pass over
 * it. There is nothing here to deduplicate because the service did.
 *
 * ============================================================================
 * WHAT "BREACH" MEANS HERE — STATED AGAIN SO IT IS NEVER OVER-READ
 * ============================================================================
 * This is an OPERATIONAL warning: a visitor has been waiting longer than the
 * desk wants anyone to wait. It is not a promise made to the visitor, and
 * nothing on the visitor surface ever shows a countdown because of it.
 */
final class ScanChatSlaBreachesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 60;

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return 'chat-sla-scan';
    }

    public function handle(ChatSlaService $sla): void
    {
        try {
            $raised = $sla->scanForBreaches();

            if ($raised->isNotEmpty()) {
                Log::warning('chat.sla_breaches_raised', [
                    'count' => $raised->count(),
                    'references' => $raised
                        ->map(fn ($conversation): string => (string) $conversation->public_reference)
                        ->all(),
                ]);
            }
        } catch (Throwable $e) {
            // A failed scan is not fatal — the next scheduled run rescans,
            // and the idempotent raise means nothing is lost or duplicated.
            Log::error('chat.sla_scan_failed', ['error' => $e->getMessage()]);

            throw $e;
        }
    }
}
