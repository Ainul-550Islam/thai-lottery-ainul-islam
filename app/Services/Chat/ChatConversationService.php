<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\DTOs\Chat\ChatStartData;
use App\Enums\Chat\ChatCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Enums\Chat\ChatPriority;
use App\Exceptions\ChatException;
use App\Jobs\Chat\DispatchChatTranscriptJob;
use App\Models\Chat\ChatConversation;
use App\Models\Chat\ChatEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conversation lifecycle service: open/resume, claim, assign, release, priority, and close.
 */
final class ChatConversationService
{
    public const REFERENCE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const REFERENCE_SUFFIX_LENGTH = 8;

    public function open(ChatStartData $data): ChatConversation
    {
        if (! (bool) config('chat.enabled', true)) {
            throw ChatException::featureDisabled();
        }

        if ($data->isGuest() && ! (bool) config('chat.guest.enabled', true)) {
            throw ChatException::guestChatDisabled();
        }

        if ($data->userId === null && ($data->guestTokenHash === null || $data->guestTokenHash === '')) {
            throw ChatException::guestIdentityRequired();
        }

        return DB::transaction(function () use ($data): ChatConversation {
            $existing = $this->findOpenFor($data, lock: true);

            if ($existing instanceof ChatConversation) {
                $changed = false;

                if ($data->userId !== null && $existing->user_id === null) {
                    $existing->user_id = $data->userId;
                    $changed = true;

                    ChatEvent::query()->create([
                        'chat_conversation_id' => (int) $existing->getKey(),
                        'event' => ChatEvent::CONVERSATION_CLAIMED_BY_VISITOR,
                        'actor_user_id' => $data->userId,
                        'actor_type' => 'visitor',
                        'occurred_at' => Carbon::now(),
                    ]);
                }

                $name = $data->normalisedGuestName();
                if ($name !== null && ($existing->guest_name === null || $existing->guest_name === '')) {
                    $existing->guest_name = $name;
                    $changed = true;
                }

                $email = $this->normaliseEmail($data->guestEmail);
                if ($email !== null && ($existing->guest_email === null || $existing->guest_email === '')) {
                    $existing->guest_email = $email;
                    $changed = true;
                }

                if ($changed) {
                    $existing->save();
                }

                ChatEvent::query()->create([
                    'chat_conversation_id' => (int) $existing->getKey(),
                    'event' => ChatEvent::CONVERSATION_RESUMED,
                    'actor_user_id' => $data->userId,
                    'actor_type' => 'visitor',
                    'occurred_at' => Carbon::now(),
                ]);

                return $existing->fresh();
            }

            $now = Carbon::now();
            $conversation = new ChatConversation([
                'uuid' => (string) Str::uuid(),
                'public_reference' => $this->generateReference(),
                'user_id' => $data->userId,
                'guest_token_hash' => $data->guestTokenHash,
                'guest_name' => $data->normalisedGuestName(),
                'guest_email' => $this->normaliseEmail($data->guestEmail),
                'priority' => ChatPriority::Normal,
                'meta' => $data->meta,
            ]);

            $conversation->status = ChatConversationStatus::Waiting;
            $conversation->queued_at = $now;
            $conversation->visitor_unread_count = 0;
            $conversation->agent_unread_count = 0;
            $conversation->message_count = 0;
            $conversation->save();

            ChatEvent::query()->create([
                'chat_conversation_id' => (int) $conversation->getKey(),
                'event' => ChatEvent::CONVERSATION_CREATED,
                'actor_user_id' => $data->userId,
                'actor_type' => 'visitor',
                'occurred_at' => $now,
            ]);

            return $conversation->fresh();
        });
    }

    public function findOpenFor(ChatStartData $data, bool $lock = false): ?ChatConversation
    {
        if ($data->userId === null && ($data->guestTokenHash === null || $data->guestTokenHash === '')) {
            return null;
        }

        $query = ChatConversation::query()->open();

        if ($lock) {
            $query->lockForUpdate();
        }

        if ($data->userId !== null && $data->guestTokenHash !== null && $data->guestTokenHash !== '') {
            $query->where(function ($q) use ($data): void {
                $q->where('user_id', $data->userId)
                    ->orWhere(function ($guestQ) use ($data): void {
                        $guestQ->whereNull('user_id')
                            ->where('guest_token_hash', $data->guestTokenHash);
                    });
            });
        } elseif ($data->userId !== null) {
            $query->where('user_id', $data->userId);
        } else {
            $query->where('guest_token_hash', $data->guestTokenHash);
        }

        return $query->orderByDesc('id')->first();
    }

    public function findLatestFor(ChatStartData $data, bool $lock = false): ?ChatConversation
    {
        if ($data->userId === null && ($data->guestTokenHash === null || $data->guestTokenHash === '')) {
            return null;
        }

        $query = ChatConversation::query();

        if ($lock) {
            $query->lockForUpdate();
        }

        if ($data->userId !== null && $data->guestTokenHash !== null && $data->guestTokenHash !== '') {
            $query->where(function ($q) use ($data): void {
                $q->where('user_id', $data->userId)
                    ->orWhere('guest_token_hash', $data->guestTokenHash);
            });
        } elseif ($data->userId !== null) {
            $query->where('user_id', $data->userId);
        } else {
            $query->where('guest_token_hash', $data->guestTokenHash);
        }

        return $query->orderByDesc('id')->first();
    }

    public function claim(ChatConversation $conversation, int $agentUserId): ChatConversation
    {
        $targetAgent = User::query()->whereKey($agentUserId)->first();
        if (! $targetAgent instanceof User) {
            throw ChatException::agentNotAvailable($agentUserId);
        }

        return DB::transaction(function () use ($conversation, $agentUserId): ChatConversation {
            /** @var ChatConversation $locked */
            $locked = ChatConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());

            if ($locked->isClosed()) {
                throw ChatException::conversationClosed((int) $locked->getKey());
            }

            if ($locked->assigned_agent_id !== null && (int) $locked->assigned_agent_id !== $agentUserId) {
                throw ChatException::alreadyClaimed((int) $locked->getKey());
            }

            if ($locked->assigned_agent_id !== null && (int) $locked->assigned_agent_id === $agentUserId) {
                return $locked;
            }

            $maxConcurrent = max(1, (int) config('chat.queue.max_concurrent_per_agent', 8));
            $currentLoad = ChatConversation::query()
                ->active()
                ->where('assigned_agent_id', $agentUserId)
                ->whereKeyNot($locked->getKey())
                ->count();

            if ($currentLoad >= $maxConcurrent) {
                throw ChatException::agentAtCapacity($agentUserId, $maxConcurrent);
            }

            $now = Carbon::now();
            $locked->assigned_agent_id = $agentUserId;
            $locked->claimed_at ??= $now;
            $locked->status = ChatConversationStatus::Active;
            $locked->save();

            ChatEvent::query()->create([
                'chat_conversation_id' => (int) $locked->getKey(),
                'event' => ChatEvent::CONVERSATION_CLAIMED,
                'actor_user_id' => $agentUserId,
                'actor_type' => 'agent',
                'context' => ['assigned_agent_id' => $agentUserId],
                'occurred_at' => $now,
            ]);

            return $locked->fresh();
        });
    }

    public function assign(ChatConversation $conversation, int $agentUserId, ?User $actor = null): ChatConversation
    {
        $targetAgent = User::query()->whereKey($agentUserId)->first();
        if (! $targetAgent instanceof User) {
            throw ChatException::agentNotAvailable($agentUserId);
        }

        return DB::transaction(function () use ($conversation, $agentUserId, $actor): ChatConversation {
            /** @var ChatConversation $locked */
            $locked = ChatConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());

            if ($locked->isClosed()) {
                throw ChatException::conversationClosed((int) $locked->getKey());
            }

            $maxConcurrent = max(1, (int) config('chat.queue.max_concurrent_per_agent', 8));
            $currentLoad = ChatConversation::query()
                ->active()
                ->where('assigned_agent_id', $agentUserId)
                ->whereKeyNot($locked->getKey())
                ->count();

            if ($currentLoad >= $maxConcurrent) {
                throw ChatException::agentAtCapacity($agentUserId, $maxConcurrent);
            }

            $previousAgentId = $locked->assigned_agent_id;
            $now = Carbon::now();

            $locked->assigned_agent_id = $agentUserId;
            $locked->claimed_at ??= $now;
            $locked->status = ChatConversationStatus::Active;
            $locked->save();

            ChatEvent::query()->create([
                'chat_conversation_id' => (int) $locked->getKey(),
                'event' => $previousAgentId === null
                    ? ChatEvent::CONVERSATION_ASSIGNED
                    : ChatEvent::CONVERSATION_REASSIGNED,
                'actor_user_id' => $actor ? (int) $actor->getAuthIdentifier() : $agentUserId,
                'actor_type' => 'agent',
                'context' => [
                    'previous_agent_id' => $previousAgentId,
                    'assigned_agent_id' => $agentUserId,
                ],
                'occurred_at' => $now,
            ]);

            return $locked->fresh();
        });
    }

    public function release(ChatConversation $conversation, ?User $actor = null): ChatConversation
    {
        return DB::transaction(function () use ($conversation, $actor): ChatConversation {
            /** @var ChatConversation $locked */
            $locked = ChatConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());

            if ($locked->isClosed()) {
                throw ChatException::conversationClosed((int) $locked->getKey());
            }

            $previousAgentId = $locked->assigned_agent_id;
            $now = Carbon::now();

            $locked->assigned_agent_id = null;
            $locked->status = ChatConversationStatus::Waiting;
            $locked->queued_at ??= $now;
            $locked->save();

            ChatEvent::query()->create([
                'chat_conversation_id' => (int) $locked->getKey(),
                'event' => ChatEvent::AGENT_LEFT,
                'actor_user_id' => $actor ? (int) $actor->getAuthIdentifier() : $previousAgentId,
                'actor_type' => 'agent',
                'context' => ['previous_agent_id' => $previousAgentId],
                'occurred_at' => $now,
            ]);

            return $locked->fresh();
        });
    }

    public function close(
        ChatConversation $conversation,
        ChatCloseReason $reason,
        ?User $actor = null,
    ): ChatConversation {
        $closed = DB::transaction(function () use ($conversation, $reason, $actor): ChatConversation {
            /** @var ChatConversation $locked */
            $locked = ChatConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());

            if ($locked->isClosed()) {
                return $locked;
            }

            $now = Carbon::now();
            $locked->status = ChatConversationStatus::Closed;
            $locked->close_reason = $reason;
            $locked->closed_at = $now;
            $locked->closed_by_user_id = $actor ? (int) $actor->getAuthIdentifier() : null;
            $locked->save();

            ChatEvent::query()->create([
                'chat_conversation_id' => (int) $locked->getKey(),
                'event' => $reason === ChatCloseReason::Idle
                    ? ChatEvent::IDLE_CLOSED
                    : ChatEvent::CONVERSATION_CLOSED,
                'actor_user_id' => $actor ? (int) $actor->getAuthIdentifier() : null,
                'actor_type' => $reason === ChatCloseReason::Visitor
                    ? 'visitor'
                    : ($reason === ChatCloseReason::Idle ? 'system' : 'agent'),
                'context' => ['reason' => $reason->value],
                'occurred_at' => $now,
            ]);

            return $locked->fresh();
        });

        if ((bool) config('chat.transcript.enabled', true)
            && is_string($closed->guest_email)
            && trim($closed->guest_email) !== ''
            && $closed->transcript_sent_at === null) {
            DispatchChatTranscriptJob::dispatch((int) $closed->getKey())->afterCommit();
        }

        return $closed;
    }

    public function setPriority(
        ChatConversation $conversation,
        ChatPriority $priority,
        ?User $actor = null,
    ): ChatConversation {
        return DB::transaction(function () use ($conversation, $priority): ChatConversation {
            /** @var ChatConversation $locked */
            $locked = ChatConversation::query()->lockForUpdate()->findOrFail($conversation->getKey());

            if ($locked->isClosed()) {
                throw ChatException::conversationClosed((int) $locked->getKey());
            }

            $locked->priority = $priority;
            $locked->save();

            return $locked->fresh();
        });
    }

    private function normaliseEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $trimmed = strtolower(trim($email));
        if ($trimmed === '') {
            return null;
        }

        $max = (int) config('chat.guest.email_max', 190);

        return mb_substr($trimmed, 0, $max);
    }

    private function generateReference(): string
    {
        $alphabetLength = strlen(self::REFERENCE_ALPHABET);

        for ($attempt = 0; $attempt < 16; $attempt++) {
            $suffix = '';
            for ($i = 0; $i < self::REFERENCE_SUFFIX_LENGTH; $i++) {
                $suffix .= self::REFERENCE_ALPHABET[random_int(0, $alphabetLength - 1)];
            }

            $candidate = 'CH-'.$suffix;
            if (! ChatConversation::query()->where('public_reference', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'CH-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, self::REFERENCE_SUFFIX_LENGTH));
    }
}
