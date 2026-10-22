<?php

declare(strict_types=1);

namespace App\Jobs\Chat;

use App\Enums\QueueName;
use App\Services\Chat\ChatMessageService;
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
 * Scheduled sweep: warn conversations that have gone quiet.
 *
 * ============================================================================
 * THE JOB OWNS THE CADENCE, THE SERVICE OWNS THE RULES
 * ============================================================================
 * Everything that defines "due for a warning" lives in
 * ChatSlaService::conversationsDueForIdleWarning(), and the actual
 * warn-and-stamp is ChatSlaService::warnIdle(). This job is the thin
 * scheduled trigger that calls them — following the exact division used by
 * CloseExpiredClaimWindowsJob and the other sweepers in this repository.
 *
 * ============================================================================
 * WHY warnIdle STAMPS BEFORE IT SPEAKS
 * ============================================================================
 * The stamp (idle_warned_at) is what makes the warning fire ONCE: the
 * due-selector only matches rows whose stamp is NULL. A job that ran twice
 * in the same minute would therefore send one warning, not two. The message
 * to the visitor comes after the stamp, and if the conversation closed in
 * between, warnIdle() notices and skips the message.
 *
 * ============================================================================
 * FAILURE POSTURE
 * ============================================================================
 * One conversation that fails to warn must not stop the rest: each warn is
 * wrapped, the failure is logged with the conversation id, and the sweep
 * continues. The whole job is retriable — a missed warning simply gets
 * picked up by the next run, because the row still matches the selector.
 */
final class WarnIdleChatConversationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * A stuck instance would double-warn nobody (the stamp prevents that),
     * but uniqueness keeps the queue honest during a backlog.
     */
    public int $uniqueFor = 60;

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return 'chat-warn-idle';
    }

    public function handle(ChatSlaService $sla, ChatMessageService $messages): void
    {
        $due = $sla->conversationsDueForIdleWarning();

        $warned = 0;
        $failed = 0;

        foreach ($due as $conversation) {
            try {
                if ($sla->warnIdle($conversation, $messages)) {
                    $warned++;
                }
            } catch (Throwable $e) {
                $failed++;

                Log::error('chat.idle_warn_failed', [
                    'chat_conversation_id' => $conversation->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($warned > 0 || $failed > 0) {
            Log::info('chat.idle_warn_sweep', [
                'warned' => $warned,
                'failed' => $failed,
            ]);
        }
    }
}
