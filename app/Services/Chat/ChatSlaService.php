<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\Chat\ChatCloseReason;
use App\Models\Chat\ChatConversation;
use App\Models\Chat\ChatEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SLA breach detection, idle warning delivery, and automatic idle conversation closure.
 */
final class ChatSlaService
{
    /**
     * @return Collection<int, ChatConversation>
     */
    public function conversationsDueForIdleWarning(?Carbon $now = null): Collection
    {
        $now ??= Carbon::now();
        $minutes = max(1, (int) config('chat.queue.idle_warning_minutes', 5));
        $cutoff = $now->copy()->subMinutes($minutes);

        return ChatConversation::query()
            ->open()
            ->whereNull('idle_warned_at')
            ->where(function ($q) use ($cutoff): void {
                $q->where('last_message_at', '<=', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff): void {
                        $inner->whereNull('last_message_at')
                            ->where('created_at', '<=', $cutoff);
                    });
            })
            ->orderBy('id')
            ->limit(200)
            ->get();
    }

    public function warnIdle(
        ChatConversation $conversation,
        ChatMessageService $messages,
        ?Carbon $now = null,
    ): bool {
        $now ??= Carbon::now();

        return DB::transaction(function () use ($conversation, $messages, $now): bool {
            /** @var ChatConversation|null $locked */
            $locked = ChatConversation::query()->lockForUpdate()->find($conversation->getKey());

            if (! $locked instanceof ChatConversation || $locked->isClosed() || $locked->idle_warned_at !== null) {
                return false;
            }

            $locked->idle_warned_at = $now;
            $locked->save();

            $messages->addSystemMessage(
                $locked,
                'Are you still there? This chat will close soon due to inactivity.',
            );

            ChatEvent::query()->create([
                'chat_conversation_id' => (int) $locked->getKey(),
                'event' => ChatEvent::IDLE_WARNED,
                'actor_user_id' => null,
                'actor_type' => 'system',
                'occurred_at' => $now,
            ]);

            return true;
        });
    }

    /**
     * @return Collection<int, ChatConversation>
     */
    public function conversationsDueForIdleClose(?Carbon $now = null): Collection
    {
        $now ??= Carbon::now();
        $minutes = max(1, (int) config('chat.queue.idle_close_minutes', 30));
        $cutoff = $now->copy()->subMinutes($minutes);

        return ChatConversation::query()
            ->open()
            ->where(function ($q) use ($cutoff): void {
                $q->where('last_message_at', '<=', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff): void {
                        $inner->whereNull('last_message_at')
                            ->where('created_at', '<=', $cutoff);
                    });
            })
            ->orderBy('id')
            ->limit(200)
            ->get();
    }

    public function closeIdle(
        ChatConversation $conversation,
        ChatConversationService $conversations,
        ChatMessageService $messages,
    ): bool {
        /** @var ChatConversation|null $fresh */
        $fresh = ChatConversation::query()->find($conversation->getKey());
        if (! $fresh instanceof ChatConversation || $fresh->isClosed()) {
            return false;
        }

        $messages->addSystemMessage(
            $fresh,
            'This conversation was automatically closed due to inactivity.',
        );

        $conversations->close($fresh, ChatCloseReason::Idle);

        return true;
    }

    /**
     * @return Collection<int, ChatConversation>
     */
    public function scanForBreaches(?Carbon $now = null): Collection
    {
        $now ??= Carbon::now();
        $targetSeconds = max(1, (int) config('chat.queue.first_response_warning_seconds', 180));
        $cutoff = $now->copy()->subSeconds($targetSeconds);

        $candidates = ChatConversation::query()
            ->open()
            ->whereNull('first_response_at')
            ->where('queued_at', '<=', $cutoff)
            ->whereDoesntHave('events', function ($q): void {
                $q->where('event', ChatEvent::SLA_BREACHED);
            })
            ->orderBy('id')
            ->limit(200)
            ->get();

        $breached = collect();

        foreach ($candidates as $conversation) {
            ChatEvent::query()->create([
                'chat_conversation_id' => (int) $conversation->getKey(),
                'event' => ChatEvent::SLA_BREACHED,
                'actor_user_id' => null,
                'actor_type' => 'system',
                'context' => [
                    'target_seconds' => $targetSeconds,
                    'queued_at' => $conversation->queued_at?->toIso8601String(),
                ],
                'occurred_at' => $now,
            ]);

            $breached->push($conversation);
        }

        return $breached;
    }
}
