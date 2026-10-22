<?php

declare(strict_types=1);

namespace App\Jobs\Chat;

use App\Enums\QueueName;
use App\Models\Chat\ChatConversation;
use App\Services\Chat\ChatTranscriptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queue the transcript email for one closed conversation.
 *
 * ============================================================================
 * WHY THIS IS A JOB AND NOT INLINE AT CLOSE TIME
 * ============================================================================
 * Closing a conversation is on the visitor's request path. Sending mail —
 * even to the `log` mailer — is slow relative to the JSON response the
 * visitor is waiting for, and on a real SMTP transport it can take seconds.
 * Deferring it keeps the close instant and lets the mailer retry on its own
 * schedule. The notifications queue (config 'chat.transcript.queue') is the
 * same lane the platform's other visitor mails use.
 *
 * ============================================================================
 * IDEMPOTENT BY DESIGN
 * ============================================================================
 * dispatchIfDue() refuses to send when transcript_sent_at is already set,
 * so a job that is delivered twice (a retry after a worker crash, a re-queue
 * during deploy) mails exactly once. This job therefore carries no
 * ShouldBeUnique lock of its own — the service-level guard is stronger, and
 * a unique lock could suppress a legitimate send the lock expired around.
 *
 * A conversation that no longer exists (deleted by retention or a bad id)
 * is logged and dropped, not retried forever.
 */
final class DispatchChatTranscriptJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * A transcript that cannot be sent after a day of retries is not going
     * to recover by retrying longer; the failure is audited either way.
     */
    public int $tries = 5;

    /**
     * Back off so a struggling mailer is not hammered: 30s, 2m, 10m, 30m.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function __construct(
        public int $chatConversationId,
    ) {
        $queue = (string) config('chat.transcript.queue', QueueName::Notifications->value);

        $this->onQueue($queue);
    }

    public function handle(ChatTranscriptService $transcripts): void
    {
        $conversation = ChatConversation::query()
            ->whereKey($this->chatConversationId)
            ->first();

        if (! $conversation instanceof ChatConversation) {
            Log::warning('chat.transcript_job_missing_conversation', [
                'chat_conversation_id' => $this->chatConversationId,
            ]);

            return;
        }

        $transcripts->dispatchIfDue($conversation);
    }
}
