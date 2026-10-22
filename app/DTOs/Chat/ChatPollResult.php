<?php

declare(strict_types=1);

namespace App\DTOs\Chat;

use App\Enums\Chat\ChatConversationStatus;

/**
 * The result of a poll: everything the client needs to redraw itself.
 *
 * ============================================================================
 * CURSOR, NOT TIMESTAMP
 * ============================================================================
 * `nextCursor` is a message id. A client asks "give me everything after 418"
 * and the server answers exactly that. A timestamp cursor would be wrong in
 * both directions: two messages inside one second make "after 12:00:01" an
 * ambiguous question, and a client whose clock is a few seconds fast would
 * silently skip messages it never saw.
 *
 * The server therefore owns the cursor. The client only echoes it back.
 *
 * ============================================================================
 * @phpstan-type ChatMessageList array<int, ChatMessageData>
 */
final readonly class ChatPollResult
{
    /**
     * @param  ChatMessageList  $messages
     * @param  array<string, mixed>  $conversation
     */
    public function __construct(
        /**
         * New messages, oldest first, already ordered by id.
         *
         * @var ChatMessageList
         */
        public array $messages,

        /**
         * The id to send back on the next poll. Never decreases.
         */
        public int $nextCursor,

        /**
         * Where the conversation stands now. Included on every poll because
         * a state change (an agent claimed it, it was closed) is exactly the
         * kind of thing a message list cannot express.
         */
        public ChatConversationStatus $status,

        /**
         * Unread count for THIS side after this poll.
         */
        public int $unreadCount,

        /**
         * Whether an agent is currently handling it.
         */
        public bool $hasAgent,

        /**
         * The agent's display name when one is assigned, so the visitor sees
         * a person rather than "Support".
         */
        public ?string $agentDisplayName,

        /**
         * Server-authoritative timestamp, so a client never has to trust its
         * own clock to render "3 minutes ago".
         */
        public string $serverTimeIso,

        /**
         * The next interval this client should use, in seconds. The SERVER
         * decides the cadence, so a client cannot poll itself into a rate
         * limit, and an operator can slow every client down at once during
         * an incident by changing one config value.
         */
        public int $nextPollSeconds,

        /**
         * Trimmed conversation state the client reads but does not own.
         *
         * @var array<string, mixed>
         */
        public array $conversation = [],
    ) {}

    /**
     * Is there anything new this poll?
     */
    public function hasNewMessages(): bool
    {
        return $this->messages !== [];
    }

    /**
     * The wire format.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => 'OK',
            'conversation' => $this->conversation + [
                'state' => $this->status->value,
                'has_agent' => $this->hasAgent,
                'agent_display_name' => $this->agentDisplayName,
                'unread_count' => $this->unreadCount,
            ],
            'messages' => array_map(
                static fn (ChatMessageData $message): array => $message->toArray(),
                $this->messages,
            ),
            'next_cursor' => $this->nextCursor,
            'server_time' => $this->serverTimeIso,
            'next_poll_seconds' => $this->nextPollSeconds,
        ];
    }
}
