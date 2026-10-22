<?php

declare(strict_types=1);

namespace App\Enums\Chat;

/*
|--------------------------------------------------------------------------
| Chat message kind
|--------------------------------------------------------------------------
|
| Text   a normal message from a visitor or an agent.
| Note   an INTERNAL note. Visible to staff only, never returned to the
|        visitor by any endpoint, never included in a transcript email.
|        Stored in the same table because it is part of the same
|        conversation timeline an agent reads in order.
| System a platform-generated state line ("Agent joined", "Closed after
|        inactivity"). Not editable, not replyable.
|
| WHY 'internal' IS NOT A BOOLEAN, AS IT IS ON support_messages
| support_messages carries a boolean `internal` column. That works when
| the only alternative to an internal note is a normal reply. A chat also
| has to place SYSTEM lines in the timeline, which are neither. A boolean
| would have forced system notices to masquerade as one of the two, and
| whichever one it picked would have been wrong: as an internal note the
| visitor never learns their chat was closed, as a reply it inflates the
| first-response metric. Three real states, three cases.
*/
enum ChatMessageKind: string
{
    case Text = 'text';
    case Note = 'note';
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
            self::Text => 'Message',
            self::Note => 'Internal note',
            self::System => 'System',
        };
    }

    /**
     * Whether the visitor is allowed to see this message.
     *
     * The single source of truth for that question. Every visitor-facing
     * query and the transcript builder filter through this method rather
     * than repeating a literal, so a future kind cannot be leaked by a
     * path that forgot to add it to an exclusion list.
     */
    public function isVisibleToVisitor(): bool
    {
        return $this !== self::Note;
    }

    /**
     * Whether the visitor is allowed to CREATE this kind.
     * A visitor may only ever write Text.
     */
    public function isWritableByVisitor(): bool
    {
        return $this === self::Text;
    }

    /**
     * Whether an agent may create this kind.
     * An agent writes Text and Note; System is platform-owned.
     */
    public function isWritableByAgent(): bool
    {
        return $this === self::Text || $this === self::Note;
    }

    /**
     * Kinds that appear in a transcript emailed to the visitor.
     *
     * @return array<int, self>
     */
    public static function transcriptKinds(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $kind): bool => $kind->isVisibleToVisitor(),
        ));
    }
}
