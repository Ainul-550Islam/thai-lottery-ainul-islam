<?php

declare(strict_types=1);

namespace App\Jobs\Chat;

use App\Enums\QueueName;
use App\Services\Chat\ChatConversationService;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatRateLimitService;
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
 * Scheduled sweep: close conversations that have been silent too long.
 *
 * ============================================================================
 * THE CLOSE IS THE SERVICE'S CONDITIONAL CLOSE
 * ============================================================================
 * This job never writes conversation state itself. It asks
 * ChatSlaService::closeIdle(), which delegates the state change to
 * ChatConversationService::close() — the SAME conditional close a human
 * agent uses. So "first close wins and the reason is preserved" holds for
 * idle closes exactly as it does for deliberate ones; an agent who closed
 * the chat seconds before the sweep ran is not overwritten by it.
 *
 * ============================================================================
 * TRANSCRIPT BEFORE FORGETTING
 * ============================================================================
 * A visitor who idled out still deserves their copy. The close itself (via
 * ChatConversationService) queues the transcript job after commit; the
 * dispatch is idempotent via transcript_sent_at, so a retry of this job
 * cannot mail twice. The rate limit bucket is cleared after close, so a
 * returning visitor starts fresh.
 */
final class CloseIdleChatConversationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return 'chat-close-idle';
    }

    public function handle(
        ChatSlaService $sla,
        ChatConversationService $conversations,
        ChatMessageService $messages,
        ChatRateLimitService $rateLimits,
    ): void {
        $due = $sla->conversationsDueForIdleClose();

        $closed = 0;
        $failed = 0;

        foreach ($due as $conversation) {
            try {
                // closeIdle() -> close() queues the transcript job itself.
                if ($sla->closeIdle($conversation, $conversations, $messages)) {
                    $closed++;

                    $rateLimits->forgetConversation((int) $conversation->getKey());
                }
            } catch (Throwable $e) {
                $failed++;

                Log::error('chat.idle_close_failed', [
                    'chat_conversation_id' => $conversation->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($closed > 0 || $failed > 0) {
            Log::info('chat.idle_close_sweep', [
                'closed' => $closed,
                'failed' => $failed,
            ]);
        }
    }
}
