<?php

declare(strict_types=1);

namespace App\Enums\Chat;

/*
|--------------------------------------------------------------------------
| Chat conversation lifecycle
|--------------------------------------------------------------------------
|
| Waiting  a visitor is queued and no agent has claimed the conversation.
|          The ONLY state in which an agent may claim it.
| Active   an agent holds it. Both sides may send messages.
| Closed   terminal for the default flow. Messages are refused; the row,
|          its reference and its transcript survive.
|
| WHY 'waiting' IS NOT CALLED 'open'
| A ticket is 'open' until someone closes it — time is not part of the
| meaning. A chat in the queue has a CLOCK attached: how long has this
| person been waiting for a human. Naming the state after that fact keeps
| the SLA, the alert job and the operator sort order reading the same
| concept instead of three code paths that agree by accident.
|
| Reopening is deliberately absent. A visitor who comes back tomorrow gets
| a new conversation; a support case that must be revisited is what the
| ticket surface is for.
*/
enum ChatConversationStatus: string
{
    case Waiting = 'waiting';
    case Active = 'active';
    case Closed = 'closed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting',
            self::Active => 'Active',
            self::Closed => 'Closed',
        };
    }

    /**
     * Whether an agent may claim a conversation in this state.
     */
    public function isClaimable(): bool
    {
        return $this === self::Waiting;
    }

    /**
     * Whether either party may still add a message.
     */
    public function acceptsMessages(): bool
    {
        return $this !== self::Closed;
    }

    /**
     * Whether the conversation still occupies a slot in an agent's workload.
     * Only ACTIVE conversations count against max_concurrent_per_agent:
     * a waiting one has not been picked up, and a closed one is finished.
     */
    public function countsAgainstAgentLoad(): bool
    {
        return $this === self::Active;
    }

    /**
     * Statuses a queue listing should include by default.
     *
     * @return array<int, self>
     */
    public static function openStatuses(): array
    {
        return [self::Waiting, self::Active];
    }
}
