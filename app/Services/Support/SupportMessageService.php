<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Chat\ChatConversation;
use App\Models\Chat\ChatMessage;
use App\Services\Chat\ChatMessageService as ChatStackMessageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Member-side messages and the one rating. Delegates every write to the chat stack.
 *
 * SENDING
 *   support -> ChatMessageService::sendFromVisitor(), which already enforces
 *   "closed conversations accept no messages", de-duplicates by client token,
 *   updates last_message_at and the agent unread counter, and emits the chat
 *   events the operator console listens to.
 *
 * READING
 *   messages are read through the conversation's visibleMessages() relation, so a
 *   member never sees an internal operator NOTE. That filter already exists in the
 *   chat stack; re-implementing it here would be the second answer to "what may a
 *   visitor see".
 *
 * RATING — THE ONE THING THE SCHEMA DID NOT HAVE
 *   A rating is not a message: it must survive message retention and redaction,
 *   and it is one-per-conversation. It is stored on chat_conversations.rating
 *   (added by migration 2026_11_13_000800) and is write-once: a second rating is
 *   refused rather than silently overwriting a member's first opinion.
 */
final class SupportMessageService
{
    public function __construct(
        private readonly ChatStackMessageService $messages,
        private readonly SupportConversationService $conversations,
    ) {
    }

    /**
     * @return Collection<int, ChatMessage>
     */
    public function listFor(int $memberId, string $conversationIdentifier, int $afterId = 0): Collection
    {
        $conversation = $this->conversations->findOwnedByIdentifier($memberId, $conversationIdentifier);

        if (! $conversation instanceof ChatConversation) {
            throw new SupportRefused('conversation_not_found', 'That conversation does not exist.', 404);
        }

        return $conversation->visibleMessages()
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit(200)
            ->get();
    }

    /**
     * Send a member message.
     *
     * @throws SupportRefused
     */
    public function send(
        int $memberId,
        string $conversationIdentifier,
        string $body,
        ?string $clientToken = null,
    ): ChatMessage {
        $conversation = $this->conversations->findOwnedByIdentifier($memberId, $conversationIdentifier);

        if (! $conversation instanceof ChatConversation) {
            throw new SupportRefused('conversation_not_found', 'That conversation does not exist.', 404);
        }

        $max = max(1, (int) config('player_experience.support.max_message_length', 4000));

        if (mb_strlen(trim($body)) > $max) {
            throw new SupportRefused(
                'message_too_long',
                sprintf('A message may not exceed %d characters.', $max),
                422,
            );
        }

        if ($conversation->isClosed()) {
            throw new SupportRefused(
                'conversation_closed',
                'This conversation is closed. Start a new one to continue.',
                409,
            );
        }

        return $this->messages->sendFromVisitor(
            conversation: $conversation,
            body: $body,
            visitorUserId: $memberId,
            displayName: null,
            attachments: [],
            clientToken: $clientToken,
        );
    }

    /**
     * Mark the member's side read. Delegates to the chat stack, which owns the
     * unread counters on both sides.
     */
    public function markRead(int $memberId, string $conversationIdentifier): int
    {
        $conversation = $this->conversations->findOwnedByIdentifier($memberId, $conversationIdentifier);

        if (! $conversation instanceof ChatConversation) {
            throw new SupportRefused('conversation_not_found', 'That conversation does not exist.', 404);
        }

        return $this->messages->markRead($conversation, false);
    }

    /**
     * Rate a closed conversation, once.
     *
     * WHY A CLOSED CONVERSATION ONLY
     * Rating an open conversation measures the member's impatience, not the
     * service they received. The refusal names the reason so the UI can say
     * "you can rate this once it is closed".
     *
     * @throws SupportRefused
     */
    public function rate(
        int $memberId,
        string $conversationIdentifier,
        int $rating,
        ?string $comment = null,
        ?Carbon $at = null,
    ): ChatConversation {
        $min = (int) config('player_experience.support.rating_min', 1);
        $max = (int) config('player_experience.support.rating_max', 5);

        if ($rating < $min || $rating > $max) {
            throw new SupportRefused(
                'invalid_rating',
                sprintf('A rating must be between %d and %d.', $min, $max),
                422,
            );
        }

        $conversation = $this->conversations->findOwnedByIdentifier($memberId, $conversationIdentifier);

        if (! $conversation instanceof ChatConversation) {
            throw new SupportRefused('conversation_not_found', 'That conversation does not exist.', 404);
        }

        if (! $conversation->isClosed()) {
            throw new SupportRefused(
                'conversation_still_open',
                'You can rate this conversation once it is closed.',
                409,
            );
        }

        // Write-once. Reading the raw column (rather than a computed flag) is
        // what makes the check a fact about storage.
        $already = $conversation->getAttribute('rating');

        if ($already !== null) {
            throw new SupportRefused(
                'already_rated',
                'This conversation has already been rated.',
                409,
            );
        }

        $conversation->setAttribute('rating', $rating);
        $conversation->setAttribute('rated_at', $at ?? Carbon::now());
        $conversation->setAttribute('rating_comment', $comment === null ? null : mb_substr(trim($comment), 0, 500));
        $conversation->save();

        return $conversation->fresh() ?? $conversation;
    }
}
