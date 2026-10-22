<?php

declare(strict_types=1);

namespace App\DTOs\Chat;

/**
 * The honest answer to "can a human talk to me right now?".
 *
 * ============================================================================
 * WHY THIS IS A DTO AND NOT A BOOLEAN
 * ============================================================================
 * A single `online: true/false` forces every caller to invent the sentence
 * that goes with it, and the sentences that get invented are the problem:
 * "We're online!" over an empty queue, "Leave a message" when three agents
 * are waiting. The state below is chosen by ChatAvailabilityService from
 * FACTS (agent heartbeats, business hours, queue depth) and carries the copy
 * key that matches it, so the widget renders what is true instead of what
 * would convert best.
 *
 *   Available    an agent is present and under capacity. A real person can
 *                answer shortly.
 *   Busy         agents are present but all at capacity. A human will get to
 *                it; the wait is longer and the widget says so.
 *   OutsideHours no agents on shift and the schedule says so. The widget
 *                prints the operator's own notice.
 *   Offline      nobody is present and the schedule does not explain why —
 *                an outage, an unscheduled gap, or a deployment with no
 *                staffed agents at all. The visitor is offered the
 *                asynchronous ticket form instead of a queue that would
 *                silently swallow their message.
 *   Disabled     the feature is switched off on this deployment.
 *
 * ============================================================================
 */
final readonly class ChatAvailabilityData
{
    public const STATE_AVAILABLE = 'available';
    public const STATE_BUSY = 'busy';
    public const STATE_OUTSIDE_HOURS = 'outside_hours';
    public const STATE_OFFLINE = 'offline';
    public const STATE_DISABLED = 'disabled';

    /**
     * @param  string  $state  One of the STATE_* constants.
     * @param  int  $onlineAgents  How many operators are genuinely present.
     * @param  int  $waitingConversations  Queue depth, for an honest "there are N people ahead".
     * @param  int|null  $estimatedWaitSeconds  Null when it cannot be estimated honestly.
     * @param  string  $messageKey  Translation key for the copy matching this state.
     * @param  bool  $canStartConversation  Whether the widget should offer the composer.
     * @param  bool  $offersTicketFallback  Whether to link the async support form instead.
     */
    public function __construct(
        public string $state,
        public int $onlineAgents,
        public int $waitingConversations,
        public ?int $estimatedWaitSeconds,
        public string $messageKey,
        public bool $canStartConversation,
        public bool $offersTicketFallback,
        /**
         * The next moment the schedule says somebody is back, when that is
         * known. Null means unknown — and the widget then says nothing about
         * when, rather than inventing "back at 9am".
         */
        public ?string $opensAgainAtIso = null,
    ) {}

    /**
     * A short, truthful sentence for the widget header.
     *
     * Every branch maps to a constant rather than being computed, so the six
     * possible things a visitor can be told are enumerable and reviewable.
     */
    public function summaryKey(): string
    {
        return match ($this->state) {
            self::STATE_AVAILABLE => 'chat.availability_available',
            self::STATE_BUSY => 'chat.availability_busy',
            self::STATE_OUTSIDE_HOURS => 'chat.availability_outside_hours',
            self::STATE_OFFLINE => 'chat.availability_offline',
            self::STATE_DISABLED => 'chat.availability_disabled',
            default => 'chat.availability_offline',
        };
    }

    /**
     * The wire format the widget polls.
     *
     * Counts are exposed deliberately. A visitor deciding whether to wait is
     * entitled to know that four people are already queued; hiding it makes
     * the wait feel like an accident rather than a queue.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => 'OK',
            'state' => $this->state,
            'online_agents' => $this->onlineAgents,
            'waiting_conversations' => $this->waitingConversations,
            'estimated_wait_seconds' => $this->estimatedWaitSeconds,
            'message_key' => $this->messageKey,
            'can_start_conversation' => $this->canStartConversation,
            'offers_ticket_fallback' => $this->offersTicketFallback,
            'opens_again_at' => $this->opensAgainAtIso,
        ];
    }

    /**
     * The state for a deployment that has the feature switched off.
     */
    public static function disabled(): self
    {
        return new self(
            state: self::STATE_DISABLED,
            onlineAgents: 0,
            waitingConversations: 0,
            estimatedWaitSeconds: null,
            messageKey: 'chat.availability_disabled',
            canStartConversation: false,
            offersTicketFallback: true,
        );
    }

    /**
     * Nobody is present, and the schedule does not account for it.
     *
     * `canStartConversation` is false on purpose. Accepting a message into a
     * queue with nobody watching produces a visitor who believes they have
     * been heard and an operator who never sees it — the exact failure mode
     * the ticket form exists to prevent.
     */
    public static function offline(int $waitingConversations = 0): self
    {
        return new self(
            state: self::STATE_OFFLINE,
            onlineAgents: 0,
            waitingConversations: $waitingConversations,
            estimatedWaitSeconds: null,
            messageKey: 'chat.availability_offline',
            canStartConversation: false,
            offersTicketFallback: true,
        );
    }
}
