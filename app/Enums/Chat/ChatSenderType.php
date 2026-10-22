<?php

declare(strict_types=1);

namespace App\Enums\Chat;

/*
|--------------------------------------------------------------------------
| Who wrote a chat message
|--------------------------------------------------------------------------
|
| Visitor  the person on the public site. Authenticated or anonymous.
| Agent    a member of staff answering from the operator console.
| System   the platform itself: "Agent joined", "Conversation closed after
|          30 minutes of inactivity". Rendered centred and unstyled, so a
|          reader can tell a state change from a sentence a human chose to
|          send. Never counted as a reply when measuring first response.
|
| WHY THIS IS NOT JUST A NULLABLE sender_user_id
| A nullable foreign key records WHO, and cannot express "the platform said
| this". Both a visitor message and a system notice can have a null user
| id for different reasons, and the UI, the unread counters and the SLA
| maths must all tell them apart. The distinction is a first-class fact,
| so it gets a first-class column.
*/
enum ChatSenderType: string
{
    case Visitor = 'visitor';
    case Agent = 'agent';
    case System = 'system';

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
            self::Visitor => 'Visitor',
            self::Agent => 'Agent',
            self::System => 'System',
        };
    }

    /**
     * Whether the message was written by a human on the visitor side.
     */
    public function isVisitor(): bool
    {
        return $this === self::Visitor;
    }

    /**
     * Whether the message was written by a human on the staff side.
     */
    public function isAgent(): bool
    {
        return $this === self::Agent;
    }

    /**
     * Whether this message counts as a human reply for SLA purposes.
     * System notices and internal notes never do.
     */
    public function countsAsHumanReply(): bool
    {
        return $this === self::Agent;
    }
}
