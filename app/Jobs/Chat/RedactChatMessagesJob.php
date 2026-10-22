<?php

declare(strict_types=1);

namespace App\Jobs\Chat;

use App\Enums\QueueName;
use App\Services\Chat\ChatRetentionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled sweep: redact chat content past its retention window.
 *
 * ============================================================================
 * BOUNDED, RESTARTABLE, OBSERVED
 * ============================================================================
 * One scheduled run executes sweepUntilDone() with a hard cap, which sweeps
 * in batches of `chat.retention.redact_batch_size` rows, each batch its own
 * transaction. If the run is interrupted — deploy, restart, timeout — the
 * next scheduled run resumes where the audit trail says it stopped, because
 * redacted rows no longer match the selection. Nothing is ever hard-deleted;
 * the bodies become tombstones and the rows stay, so operational counts and
 * the audit trail keep telling the truth after the content is gone.
 *
 * ============================================================================
 * WHY IT RUNS OFF-PEAK AND ALONE
 * ============================================================================
 * Redaction rewrites message rows under lock, one at a time. That is cheap
 * per row and would be wasteful during the desk's busy hours, so the
 * schedule places it at night (see bootstrap/app.php). The ShouldBeUnique
 * lock stops a backlog from stacking two sweeps on the same rows — the
 * second one would find nothing to do anyway, but not running it at all is
 * cheaper than discovering that.
 */
final class RedactChatMessagesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Longer than a plausible full sweep so an overlapping dispatch queues
     * behind the running one instead of racing it.
     */
    public int $uniqueFor = 1800;

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return 'chat-retention-redact';
    }

    public function handle(ChatRetentionService $retention): void
    {
        try {
            $result = $retention->sweepUntilDone();

            Log::info('chat.retention_sweep', [
                'redacted' => $result['redacted'],
                'sweeps' => $result['sweeps'],
                'complete' => $result['complete'],
            ]);

            if (! $result['complete']) {
                // The cap was reached before the backlog drained. Not an
                // error — the next run continues — but worth seeing.
                Log::warning('chat.retention_sweep_incomplete', [
                    'redacted_this_run' => $result['redacted'],
                ]);
            }
        } catch (Throwable $e) {
            Log::error('chat.retention_sweep_failed', ['error' => $e->getMessage()]);

            throw $e;
        }
    }
}
