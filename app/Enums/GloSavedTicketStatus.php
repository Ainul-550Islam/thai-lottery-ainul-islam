<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Saved-ticket lifecycle. Removal deactivates (Inactive) — historical
 * notification/audit evidence is never deleted.
 */
enum GloSavedTicketStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }
}
