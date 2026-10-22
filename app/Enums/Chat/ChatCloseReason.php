<?php

declare(strict_types=1);

namespace App\Enums\Chat;

/*
|--------------------------------------------------------------------------
| Why a conversation ended
|--------------------------------------------------------------------------
|
| Stored on the conversation so the closing reason is a fact that survives
| the transcript. Operational questions ("how many chats are we losing to
| inactivity?") are only answerable if the reason was recorded, and a
| reason derived later from timestamps would be a guess.
|
|   Visitor  the visitor pressed the close button.
|   Agent    an operator closed it after resolving the question.
|   Idle     the idle job closed it after queue.idle_close_minutes of
|            silence. This is the one nobody chose, and the one worth
|            counting.
|   Handoff  closed here because it continued on another channel (a ticket
|            was raised, an email was sent). Distinguished from Agent so
|            the handoff rate is measurable.
|   System   closed by an administrative action — the account was deleted,
|            the retention policy redacted the thread. Not a resolution.
|
| The closing REASON is separate from the CLOSER: an idle close has no
| closing user, and a system close may have one that did not decide
| anything.
*/
enum ChatCloseReason: string
{
    case Visitor = 'visitor';
    case Agent = 'agent';
    case Idle = 'idle';
    case Handoff = 'handoff';
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
            self::Visitor => 'Closed by visitor',
            self::Agent => 'Resolved by agent',
            self::Idle => 'Closed after inactivity',
            self::Handoff => 'Continued on another channel',
            self::System => 'Closed by the platform',
        };
    }

    /**
     * Whether a human deliberately ended the conversation. Used by the
     * unresolved-conversation metric so an agent close is not counted as a
     * loss alongside an idle timeout.
     */
    public function wasDeliberate(): bool
    {
        return $this === self::Visitor || $this === self::Agent || $this === self::Handoff;
    }
}
