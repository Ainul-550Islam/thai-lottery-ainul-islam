<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * GLO dealer (e-Service) registration/verification lifecycle.
 *
 * Additive to AgentStatus and RetailVendorStatus — those model commission
 * agents and quota retail channels; this models the e-Service dealer
 * abstraction bound to a user account. Never replaces either.
 */
enum GloDealerStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending verification',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    public function maySubmitChangeRequests(): bool
    {
        return $this === self::Active;
    }

    public function mayUpdateSalesLocation(): bool
    {
        return $this === self::Active;
    }
}
