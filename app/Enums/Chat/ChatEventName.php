<?php

declare(strict_types=1);

namespace App\Enums\Chat;

/*
|--------------------------------------------------------------------------
| Chat event vocabulary
|--------------------------------------------------------------------------
|
| The operational facts written to chat_events. These describe what HAPPENED
| to a conversation — claimed, assigned, idle-warned, redacted — as opposed
| to chat_messages, which is what the two parties said to each other.
|
| WHY AN ENUM AND NOT THE STRING CONSTANTS THAT ALREADY EXIST ON THE MODEL
| ----------------------------------------------------------------------
| App\Models\Chat\ChatEvent carries the same names as constants, because the
| health queries and the tests refer to them and a typo in a bare literal
| would silently produce an event nobody ever reads. What the model's
| constants cannot give is a CLOSED SET the type system understands: a
| parameter typed as this enum cannot receive a name that does not exist.
|
| The two are kept in step deliberately — the enum is the type-safe surface,
| the model constants remain the convenient literal for queries. A test
| asserts every enum case exists as a model constant and vice versa, so they
| cannot drift.
|
| WHY NOT A DATABASE ENUM COLUMN
| Events are vocabulary that grows as operators learn what they need to
| observe. A migration per new observation would make adding one more
| expensive than the observation is worth, and would leave older rows unable
| to describe a new situation.
*/
enum ChatEventName: string
{
    /** A brand-new conversation was placed in the queue. */
    case ConversationCreated = 'conversation_created';

    /** An existing open conversation was re-attached to a returning visitor. */
    case ConversationResumed = 'conversation_resumed';

    /** An agent took a waiting conversation. */
    case ConversationClaimed = 'conversation_claimed';

    /** An operator routed a conversation to a specific agent. */
    case ConversationAssigned = 'conversation_assigned';

    /** An already-assigned conversation was moved to a different agent. */
    case ConversationReassigned = 'conversation_reassigned';

    /** An agent joined a conversation that was already active or assigned. */
    case AgentJoined = 'agent_joined';

    /** An agent released a conversation back into the queue. */
    case AgentLeft = 'agent_left';

    /** An agent sent a message. */
    case MessageSent = 'message_sent';

    /** An agent wrote an internal note. */
    case NoteAdded = 'note_added';

    /** An attachment was accepted and stored. */
    case AttachmentAdded = 'attachment_added';

    /** An attachment was refused, with the reason. */
    case AttachmentRejected = 'attachment_rejected';

    /** The visitor was warned that the conversation is about to close. */
    case IdleWarned = 'idle_warned';

    /** A conversation was closed by a person. */
    case ConversationClosed = 'conversation_closed';

    /** The idle job closed a conversation nobody was in. */
    case IdleClosed = 'idle_closed';

    /** A transcript email was dispatched. */
    case TranscriptDispatched = 'transcript_dispatched';

    /** A transcript email could not be dispatched. */
    case TranscriptFailed = 'transcript_failed';

    /** The retention job replaced message bodies with a tombstone. */
    case RetentionRedacted = 'retention_redacted';

    /** A sender was throttled. Recorded so abuse is visible after the fact. */
    case RateLimited = 'rate_limited';

    /** A caller was refused access to a conversation. */
    case AccessDenied = 'access_denied';

    /** A conversation sat unanswered past the warning threshold. */
    case SlaBreached = 'sla_breached';

    /** A guest conversation was claimed by the account that signed in. */
    case ClaimedByVisitor = 'conversation_claimed_by_visitor';

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
            self::ConversationCreated => 'Conversation created',
            self::ConversationResumed => 'Conversation resumed',
            self::ConversationClaimed => 'Claimed by agent',
            self::ConversationAssigned => 'Assigned by operator',
            self::ConversationReassigned => 'Reassigned to another agent',
            self::AgentJoined => 'Agent joined the conversation',
            self::AgentLeft => 'Agent released the conversation',
            self::MessageSent => 'Message sent',
            self::NoteAdded => 'Internal note added',
            self::AttachmentAdded => 'Attachment stored',
            self::AttachmentRejected => 'Attachment refused',
            self::IdleWarned => 'Idle warning sent',
            self::ConversationClosed => 'Conversation closed',
            self::IdleClosed => 'Closed after inactivity',
            self::TranscriptDispatched => 'Transcript dispatched',
            self::TranscriptFailed => 'Transcript failed',
            self::RetentionRedacted => 'Content redacted by retention policy',
            self::RateLimited => 'Sender throttled',
            self::AccessDenied => 'Access refused',
            self::SlaBreached => 'Unanswered past the warning threshold',
            self::ClaimedByVisitor => 'Claimed by the signed-in visitor',
        };
    }

    /**
     * Whether this event is a refusal or a failure — the ones an operator
     * scanning the health report wants surfaced rather than buried.
     */
    public function isFailureSignal(): bool
    {
        return match ($this) {
            self::AttachmentRejected,
            self::TranscriptFailed,
            self::RateLimited,
            self::AccessDenied,
            self::SlaBreached => true,
            default => false,
        };
    }

    /**
     * Whether a human caused this, as opposed to the platform.
     *
     * Not the same question as "does the row have an actor id": an idle close
     * has no actor and is not human, and a close by an agent has one and is.
     * Used by the health report to separate "we are losing chats to
     * inactivity" from "agents are ending them".
     */
    public function isHumanInitiated(): bool
    {
        return match ($this) {
            self::IdleWarned,
            self::IdleClosed,
            self::RetentionRedacted,
            self::SlaBreached,
            self::TranscriptDispatched,
            self::TranscriptFailed => false,
            default => true,
        };
    }
}
