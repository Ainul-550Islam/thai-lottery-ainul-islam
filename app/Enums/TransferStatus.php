<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle state of a player pocket-to-pocket balance transfer.
 */
enum TransferStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }

    public static function tryFromValue(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        $normalised = strtolower(trim($value));

        if ($normalised === '') {
            return null;
        }

        return self::tryFrom($normalised);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
