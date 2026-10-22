<?php

declare(strict_types=1);

namespace App\Enums\Chat;

/*
|--------------------------------------------------------------------------
| Conversation priority
|--------------------------------------------------------------------------
|
| Ordered, and the ORDER is the only thing that matters: the queue sorts by
| priority and then by wait time, so a high-priority visitor is not left
| behind a low-priority one who happened to arrive first.
|
| Nothing in the platform may set 'urgent' automatically. Auto-escalation
| on a keyword would be a guess dressed as a decision; priority is set by a
| human or by an explicit operator rule, and the default is Normal.
*/
enum ChatPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

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
            self::Low => 'Low',
            self::Normal => 'Normal',
            self::High => 'High',
            self::Urgent => 'Urgent',
        };
    }

    /**
     * Sort weight. Higher sorts first. Explicit integers rather than the
     * case order so a future case inserted in the middle of the enum cannot
     * silently reorder the live queue.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 40,
            self::High => 30,
            self::Normal => 20,
            self::Low => 10,
        };
    }

    /**
     * The priority a conversation starts with. Named rather than inlined so
     * the default is stated once.
     */
    public static function default(): self
    {
        return self::Normal;
    }
}
