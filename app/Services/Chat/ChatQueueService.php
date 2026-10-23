<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\Chat\ChatConversationStatus;
use App\Models\Chat\ChatConversation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Priority + FIFO waiting queue and atomic operator claim operations.
 */
final class ChatQueueService
{
    public function __construct(
        private readonly ChatConversationService $conversations,
    ) {}

    public function waitingQueue(int $perPage = 25): LengthAwarePaginator
    {
        $resolvedPerPage = min(100, max(1, $perPage));

        return ChatConversation::query()
            ->waiting()
            ->with(['user', 'assignedAgent'])
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 3 WHEN 'high' THEN 2 ELSE 1 END DESC")
            ->orderBy('queued_at')
            ->orderBy('id')
            ->paginate($resolvedPerPage);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function workloadFor(int $agentUserId): array
    {
        $rows = ChatConversation::query()
            ->active()
            ->where('assigned_agent_id', $agentUserId)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        $out = [];
        foreach ($rows as $conversation) {
            $out[] = [
                'id' => (int) $conversation->getKey(),
                'uuid' => (string) $conversation->uuid,
                'public_reference' => (string) $conversation->public_reference,
                'priority' => $conversation->priority->value,
                'visitor_display_name' => $conversation->visitorDisplayName(),
                'agent_unread_count' => (int) $conversation->agent_unread_count,
                'message_count' => (int) $conversation->message_count,
                'claimed_at' => $conversation->claimed_at?->toIso8601String(),
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            ];
        }

        return $out;
    }

    public function takeNext(int $agentUserId): ?ChatConversation
    {
        return DB::transaction(function () use ($agentUserId): ?ChatConversation {
            $candidate = ChatConversation::query()
                ->where('status', ChatConversationStatus::Waiting)
                ->whereNull('assigned_agent_id')
                ->orderByRaw("CASE priority WHEN 'urgent' THEN 3 WHEN 'high' THEN 2 ELSE 1 END DESC")
                ->orderBy('queued_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $candidate instanceof ChatConversation) {
                return null;
            }

            return $this->conversations->claim($candidate, $agentUserId);
        });
    }

    public function takeSpecific(int $agentUserId, int $conversationId): ChatConversation
    {
        /** @var ChatConversation $conversation */
        $conversation = ChatConversation::query()->findOrFail($conversationId);

        return $this->conversations->claim($conversation, $agentUserId);
    }
}
