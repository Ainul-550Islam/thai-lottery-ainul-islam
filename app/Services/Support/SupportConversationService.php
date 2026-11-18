<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\DTOs\Chat\ChatStartData;
use App\Enums\Chat\ChatCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\Chat\ChatConversation;
use App\Models\User;
use App\Services\Chat\ChatConversationService as ChatStackConversationService;
use Illuminate\Support\Carbon;

/**
 * The MEMBER side of the support inbox, on top of the existing chat stack.
 *
 * REUSE, NOT A SECOND INBOX (Phase 0 finding)
 * chat_conversations / chat_messages already exist, with a 13-class
 * App\Services\Chat\* stack, an operator console, SLA timers, transcripts and
 * presence. support_conversations / support_messages were therefore NOT created.
 * This service is a thin, member-scoped façade over
 * ChatConversationService::open()/close() and the conversation model, and it is
 * the only place the member-facing rules (max open conversations, ownership) live.
 *
 * WHY A FAÇADE RATHER THAN CALLING THE CHAT SERVICE FROM THE CONTROLLER
 * Because "how many conversations may one member have open" is a support POLICY,
 * and a policy spread across controllers is a policy nobody can find. The chat
 * stack is deliberately policy-light (it serves guests, operators and members);
 * the member rules belong here.
 *
 * WHAT IT WILL NOT DO
 * It will not change a conversation's status behind the agent's back, will not
 * reopen a closed one (the chat stack models reopen as a new conversation, and
 * this service keeps that rule), and will not create a conversation for a guest:
 * this endpoint is authenticated, so there is always a member.
 */
final class SupportConversationService
{
    public function __construct(
        private readonly ChatStackConversationService $chat,
    ) {
    }

    /**
     * The member's conversations, newest activity first.
     *
     * @return \Illuminate\Support\Collection<int, ChatConversation>
     */
    public function listFor(int $memberId, ?int $limit = null): \Illuminate\Support\Collection
    {
        $max = max(1, min($limit ?? 20, (int) config('player_experience.support.max_list_limit', 50)));

        return ChatConversation::query()
            ->where('user_id', $memberId)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit($max)
            ->get();
    }

    /**
     * Open a conversation, or hand back the one already open.
     *
     * IDEMPOTENT BY DESIGN
     * A member tapping "chat" twice must not open two queues. The chat stack's
     * open() already resumes an existing open conversation for the same identity;
     * the only thing added here is the cap, which is checked BEFORE open() so the
     * refusal happens without creating a row.
     *
     * @throws SupportRefused
     */
    public function open(int $memberId, string $subject, ?string $firstMessage = null, ?Carbon $at = null): ChatConversation
    {
        $existing = $this->findOpenFor($memberId);

        if ($existing instanceof ChatConversation) {
            return $existing;
        }

        $maxOpen = max(1, (int) config('player_experience.support.max_open_conversations', 3));

        if ($this->openCountFor($memberId) >= $maxOpen) {
            throw new SupportRefused(
                'too_many_open_conversations',
                sprintf('You already have %d open conversations. Please close one before starting another.', $maxOpen),
                409,
            );
        }

        $member = User::query()->find($memberId);

        if (! $member instanceof User) {
            throw new SupportRefused('member_not_found', 'The member does not exist.', 404);
        }

        $data = new ChatStartData(
            userId: $memberId,
            guestTokenHash: null,
            guestName: $member->name,
            guestEmail: $member->email,
            meta: [
                'page_url' => null,
                'referrer' => null,
                'locale' => null,
                'user_agent_family' => 'api',
                'screen' => null,
                'subject' => mb_substr(trim($subject), 0, 190),
            ],
        );

        return $this->chat->open($data);
    }

    /**
     * Close the member's own conversation.
     *
     * Ownership is enforced here, and a foreign conversation is indistinguishable
     * from a missing one (both null) so an id probe learns nothing.
     *
     * @throws SupportRefused
     */
    public function close(int $memberId, int $conversationId, ?User $actor = null): ChatConversation
    {
        $conversation = $this->findOwned($memberId, $conversationId);

        if (! $conversation instanceof ChatConversation) {
            throw new SupportRefused('conversation_not_found', 'That conversation does not exist.', 404);
        }

        if ($conversation->isClosed()) {
            return $conversation;
        }

        return $this->chat->close($conversation, ChatCloseReason::Visitor, $actor);
    }

    /**
     * The member's own conversation, or null. The single ownership gate used by
     * every other method and by the controller's policy check.
     */
    public function findOwned(int $memberId, int $conversationId): ?ChatConversation
    {
        /** @var ChatConversation|null $conversation */
        $conversation = ChatConversation::query()
            ->where('user_id', $memberId)
            ->whereKey($conversationId)
            ->first();

        return $conversation;
    }

    /**
     * Resolve by id OR public reference, always within the member's own rows.
     */
    public function findOwnedByIdentifier(int $memberId, string $identifier): ?ChatConversation
    {
        $query = ChatConversation::query()->where('user_id', $memberId);

        if (ctype_digit($identifier)) {
            $query->whereKey((int) $identifier);
        } else {
            $query->where('public_reference', $identifier);
        }

        return $query->first();
    }

    public function findOpenFor(int $memberId): ?ChatConversation
    {
        /** @var ChatConversation|null $conversation */
        $conversation = ChatConversation::query()
            ->where('user_id', $memberId)
            ->whereIn('status', [
                ChatConversationStatus::Waiting->value,
                ChatConversationStatus::Active->value,
            ])
            ->orderByDesc('id')
            ->first();

        return $conversation;
    }

    public function openCountFor(int $memberId): int
    {
        return (int) ChatConversation::query()
            ->where('user_id', $memberId)
            ->whereIn('status', [
                ChatConversationStatus::Waiting->value,
                ChatConversationStatus::Active->value,
            ])
            ->count();
    }
}
