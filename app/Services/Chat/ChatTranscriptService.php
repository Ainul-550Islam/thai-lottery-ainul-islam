<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\Chat\ChatConversation;
use App\Models\Chat\ChatEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends a closed conversation transcript once when a visitor email is present.
 */
final class ChatTranscriptService
{
    public function __construct(
        private readonly ChatMessageService $messages,
    ) {}

    public function dispatchIfDue(ChatConversation $conversation): bool
    {
        if (! (bool) config('chat.transcript.enabled', true)) {
            return false;
        }

        return DB::transaction(function () use ($conversation): bool {
            /** @var ChatConversation|null $locked */
            $locked = ChatConversation::query()->lockForUpdate()->find($conversation->getKey());

            if (! $locked instanceof ChatConversation) {
                return false;
            }

            if (! $locked->isClosed() || $locked->transcript_sent_at !== null) {
                return false;
            }

            $recipient = is_string($locked->guest_email) ? trim($locked->guest_email) : '';
            if ($recipient === '' && $locked->user_id !== null) {
                $recipient = trim((string) optional($locked->user)->email);
            }

            if ($recipient === '') {
                return false;
            }

            $transcript = $this->messages->transcript($locked, isAgent: false, limit: 200);
            $now = Carbon::now();

            Log::info('chat.transcript.dispatched', [
                'conversation_uuid' => $locked->uuid,
                'public_reference' => $locked->public_reference,
                'recipient' => $recipient,
                'message_count' => count($transcript),
            ]);

            $locked->transcript_sent_at = $now;
            $locked->save();

            ChatEvent::query()->create([
                'chat_conversation_id' => (int) $locked->getKey(),
                'event' => ChatEvent::TRANSCRIPT_DISPATCHED,
                'actor_user_id' => null,
                'actor_type' => 'system',
                'context' => [
                    'recipient' => $recipient,
                    'message_count' => count($transcript),
                ],
                'occurred_at' => $now,
            ]);

            return true;
        });
    }
}
