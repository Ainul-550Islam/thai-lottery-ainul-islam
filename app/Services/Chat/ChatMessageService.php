<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\DTOs\Chat\ChatMessageData;
use App\DTOs\Chat\ChatPollResult;
use App\Enums\Chat\ChatMessageKind;
use App\Enums\Chat\ChatSenderType;
use App\Exceptions\ChatException;
use App\Models\Chat\ChatConversation;
use App\Models\Chat\ChatEvent;
use App\Models\Chat\ChatMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Message persistence, cursor polling, read receipts, and internal operator notes.
 */
final class ChatMessageService
{
    /**
     * @param  list<array<string, mixed>>  $attachments
     */
    public function sendFromVisitor(
        ChatConversation $conversation,
        string $body,
        ?int $visitorUserId = null,
        ?string $displayName = null,
        array $attachments = [],
        ?string $clientToken = null,
    ): ChatMessage {
        $cleanBody = $this->prepareBody($body, $attachments !== []);
        $token = $this->normaliseClientToken($clientToken);

        return DB::transaction(function () use (
            $conversation,
            $cleanBody,
            $visitorUserId,
            $displayName,
            $attachments,
            $token,
        ): ChatMessage {
            /** @var ChatConversation $locked */
            $locked = ChatConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());

            if ($locked->isClosed()) {
                throw ChatException::conversationClosed((int) $locked->getKey());
            }

            if ($token !== null) {
                $existing = ChatMessage::query()
                    ->where('chat_conversation_id', $locked->getKey())
                    ->where('sender_type', ChatSenderType::Visitor)
                    ->where('client_token', $token)
                    ->first();

                if ($existing instanceof ChatMessage) {
                    return $existing;
                }
            }

            $now = Carbon::now();
            $resolvedName = $displayName !== null && trim($displayName) !== ''
                ? mb_substr(trim($displayName), 0, 80)
                : ($locked->guest_name ?? 'Visitor');

            $kind = $attachments !== [] && $cleanBody === ''
                ? ChatMessageKind::Attachment
                : ChatMessageKind::Text;

            $message = ChatMessage::query()->create([
                'chat_conversation_id' => (int) $locked->getKey(),
                'sender_type' => ChatSenderType::Visitor,
                'sender_user_id' => $visitorUserId ?? $locked->user_id,
                'sender_display_name' => $resolvedName,
                'kind' => $kind,
                'body' => $cleanBody,
                'attachments' => $attachments !== [] ? array_values($attachments) : null,
                'client_token' => $token,
            ]);

            $locked->last_message_at = $now;
            $locked->last_visitor_message_at = $now;
            $locked->idle_warned_at = null;
            $locked->message_count = (int) $locked->message_count + 1;
            $locked->agent_unread_count = (int) $locked->agent_unread_count + 1;
            $locked->save();

            ChatEvent::query()->create([
                'chat_conversation_id' => (int) $locked->getKey(),
                'event' => ChatEvent::MESSAGE_SENT,
                'actor_user_id' => $visitorUserId ?? $locked->user_id,
                'actor_type' => 'visitor',
                'context' => ['message_id' => (int) $message->getKey()],
                'occurred_at' => $now,
            ]);

            return $message;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $attachments
     */
    public function sendFromAgent(
        ChatConversation $conversation,
        string $body,
        User $agent,
        array $attachments = [],
        ?string $clientToken = null,
    ): ChatMessage {
        $cleanBody = $this->prepareBody($body, $attachments !== []);
        $token = $this->normaliseClientToken($clientToken);
        $agentId = (int) $agent->getAuthIdentifier();
        $agentName = $this->agentDisplayName($agent);

        return DB::transaction(function () use (
            $conversation,
            $cleanBody,
            $agentId,
            $agentName,
            $attachments,
            $token,
        ): ChatMessage {
            /** @var ChatConversation $locked */
            $locked = ChatConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());

            if ($locked->isClosed()) {
                throw ChatException::conversationClosed((int) $locked->getKey());
            }

            if ($token !== null) {
                $existing = ChatMessage::query()
                    ->where('chat_conversation_id', $locked->getKey())
                    ->where('sender_type', ChatSenderType::Agent)
                    ->where('client_token', $token)
                    ->first();

                if ($existing instanceof ChatMessage) {
                    return $existing;
                }
            }

            $now = Carbon::now();
            $kind = $attachments !== [] && $cleanBody === ''
                ? ChatMessageKind::Attachment
                : ChatMessageKind::Text;

            $message = ChatMessage::query()->create([
                'chat_conversation_id' => (int) $locked->getKey(),
                'sender_type' => ChatSenderType::Agent,
                'sender_user_id' => $agentId,
                'sender_display_name' => $agentName,
                'kind' => $kind,
                'body' => $cleanBody,
                'attachments' => $attachments !== [] ? array_values($attachments) : null,
                'client_token' => $token,
            ]);

            $locked->first_response_at ??= $now;
            $locked->last_message_at = $now;
            $locked->last_agent_message_at = $now;
            $locked->message_count = (int) $locked->message_count + 1;
            $locked->visitor_unread_count = (int) $locked->visitor_unread_count + 1;
            $locked->save();

            ChatEvent::query()->create([
                'chat_conversation_id' => (int) $locked->getKey(),
                'event' => ChatEvent::MESSAGE_SENT,
                'actor_user_id' => $agentId,
                'actor_type' => 'agent',
                'context' => ['message_id' => (int) $message->getKey()],
                'occurred_at' => $now,
            ]);

            return $message;
        });
    }

    public function addNote(ChatConversation $conversation, string $body, User $agent): ChatMessage
    {
        $cleanBody = $this->prepareBody($body, false);
        $agentId = (int) $agent->getAuthIdentifier();
        $agentName = $this->agentDisplayName($agent);

        return DB::transaction(function () use ($conversation, $cleanBody, $agentId, $agentName): ChatMessage {
            /** @var ChatConversation $locked */
            $locked = ChatConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());

            if ($locked->isClosed()) {
                throw ChatException::conversationClosed((int) $locked->getKey());
            }

            $now = Carbon::now();

            $message = ChatMessage::query()->create([
                'chat_conversation_id' => (int) $locked->getKey(),
                'sender_type' => ChatSenderType::Agent,
                'sender_user_id' => $agentId,
                'sender_display_name' => $agentName,
                'kind' => ChatMessageKind::Note,
                'body' => $cleanBody,
            ]);

            ChatEvent::query()->create([
                'chat_conversation_id' => (int) $locked->getKey(),
                'event' => ChatEvent::NOTE_ADDED,
                'actor_user_id' => $agentId,
                'actor_type' => 'agent',
                'context' => ['message_id' => (int) $message->getKey()],
                'occurred_at' => $now,
            ]);

            return $message;
        });
    }

    public function addSystemMessage(ChatConversation $conversation, string $body): ChatMessage
    {
        $cleanBody = $this->prepareBody($body, false);

        return DB::transaction(function () use ($conversation, $cleanBody): ChatMessage {
            /** @var ChatConversation $locked */
            $locked = ChatConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());

            $now = Carbon::now();

            $message = ChatMessage::query()->create([
                'chat_conversation_id' => (int) $locked->getKey(),
                'sender_type' => ChatSenderType::System,
                'sender_user_id' => null,
                'sender_display_name' => 'System',
                'kind' => ChatMessageKind::System,
                'body' => $cleanBody,
            ]);

            $locked->last_message_at = $now;
            $locked->message_count = (int) $locked->message_count + 1;
            $locked->visitor_unread_count = (int) $locked->visitor_unread_count + 1;
            $locked->save();

            return $message;
        });
    }

    public function poll(ChatConversation $conversation, int $afterId, bool $isAgent): ChatPollResult
    {
        /** @var ChatConversation $fresh */
        $fresh = ChatConversation::query()->find($conversation->getKey()) ?? $conversation;
        $limit = max(1, (int) config('chat.message.page_size', 50));

        $query = ChatMessage::query()
            ->where('chat_conversation_id', $fresh->getKey())
            ->afterId(max(0, $afterId))
            ->orderBy('id')
            ->limit($limit);

        if (! $isAgent) {
            $query->visibleToVisitor();
        }

        $rows = $query->get();
        $messages = [];
        $nextCursor = max(0, $afterId);

        foreach ($rows as $row) {
            $messages[] = $this->toData($row, $isAgent);
            $nextCursor = (int) $row->getKey();
        }

        $agentDisplayName = null;
        if ($fresh->assigned_agent_id !== null) {
            $agent = User::query()->find($fresh->assigned_agent_id);
            if ($agent instanceof User) {
                $agentDisplayName = $this->agentDisplayName($agent);
            }
        }

        $nextPollSeconds = $isAgent
            ? max(1, (int) config('chat.poll.operator_interval_seconds', 5))
            : max(1, (int) config('chat.poll.visitor_interval_seconds', 4));

        return new ChatPollResult(
            messages: $messages,
            nextCursor: $nextCursor,
            status: $fresh->status,
            unreadCount: $isAgent ? (int) $fresh->agent_unread_count : (int) $fresh->visitor_unread_count,
            hasAgent: $fresh->assigned_agent_id !== null,
            agentDisplayName: $agentDisplayName,
            serverTimeIso: Carbon::now()->toIso8601String(),
            nextPollSeconds: $nextPollSeconds,
            conversation: [
                'id' => (int) $fresh->getKey(),
                'uuid' => (string) $fresh->uuid,
                'reference' => (string) $fresh->public_reference,
            ],
        );
    }

    /**
     * @return list<ChatMessageData>
     */
    public function transcript(
        ChatConversation $conversation,
        bool $isAgent,
        ?int $beforeId = null,
        ?int $limit = null,
    ): array {
        $maxPage = max(1, (int) config('chat.message.max_page_size', 200));
        $defaultPage = max(1, (int) config('chat.message.page_size', 50));
        $resolvedLimit = min($maxPage, max(1, $limit ?? $defaultPage));

        $query = ChatMessage::query()
            ->where('chat_conversation_id', $conversation->getKey());

        if (! $isAgent) {
            $query->visibleToVisitor();
        }

        if ($beforeId !== null && $beforeId > 0) {
            $query->where('id', '<', $beforeId);
        }

        $rows = $query
            ->orderByDesc('id')
            ->limit($resolvedLimit)
            ->get()
            ->reverse()
            ->values();

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->toData($row, $isAgent);
        }

        return $out;
    }

    public function markRead(ChatConversation $conversation, bool $isAgent): int
    {
        return DB::transaction(function () use ($conversation, $isAgent): int {
            /** @var ChatConversation $locked */
            $locked = ChatConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());
            $now = Carbon::now();

            if ($isAgent) {
                $updated = ChatMessage::query()
                    ->where('chat_conversation_id', $locked->getKey())
                    ->where('sender_type', ChatSenderType::Visitor)
                    ->whereNull('read_at')
                    ->update(['read_at' => $now]);

                $locked->agent_unread_count = 0;
                $locked->save();

                return (int) $updated;
            }

            $updated = ChatMessage::query()
                ->where('chat_conversation_id', $locked->getKey())
                ->where('sender_type', '!=', ChatSenderType::Visitor)
                ->visibleToVisitor()
                ->whereNull('read_at')
                ->update(['read_at' => $now]);

            $locked->visitor_unread_count = 0;
            $locked->save();

            return (int) $updated;
        });
    }

    public function toData(ChatMessage $message, bool $isAgent = false): ChatMessageData
    {
        return new ChatMessageData(
            id: (int) $message->getKey(),
            senderType: $message->sender_type,
            senderUserId: $message->sender_user_id !== null ? (int) $message->sender_user_id : null,
            senderDisplayName: $message->sender_display_name,
            kind: $message->kind ?? ChatMessageKind::Text,
            body: $message->displayBody(),
            attachments: $message->publicAttachments(),
            createdAtIso: ($message->created_at ?? Carbon::now())->toIso8601String(),
            isRead: $message->read_at !== null,
            clientToken: $message->client_token,
        );
    }

    private function prepareBody(string $body, bool $hasAttachments): string
    {
        $sanitised = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? '';
        $trimmed = trim($sanitised);

        $maxLength = max(1, (int) config('chat.message.max_length', 2000));
        if (mb_strlen($trimmed) > $maxLength) {
            throw ChatException::messageTooLong($maxLength);
        }

        if ($trimmed === '' && ! $hasAttachments) {
            throw ChatException::emptyMessage();
        }

        return $trimmed;
    }

    private function normaliseClientToken(?string $clientToken): ?string
    {
        if ($clientToken === null) {
            return null;
        }

        $trimmed = trim($clientToken);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, 64);
    }

    private function agentDisplayName(User $agent): string
    {
        foreach (['name', 'full_name', 'username'] as $attribute) {
            $value = $agent->getAttribute($attribute);
            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 80);
            }
        }

        return 'Support Agent';
    }
}
