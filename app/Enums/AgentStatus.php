<?php

namespace App\Enums;

enum AgentStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    // Backward-compatible aliases for older callers; the canonical cases remain above.
    public const ACTIVE = self::Active;
    public const INACTIVE = self::Inactive;
    public const SUSPENDED = self::Suspended;
    public const TERMINATED = self::Terminated;

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Suspended => 'Suspended',
            self::Terminated => 'Terminated',
        };
    }

    public function canOperate(): bool
    {
        return $this === self::Active;
    }

    public function canAcceptPlayers(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether commissions may be accrued for this agent on NEW bets. Only a
     * fully Active agent accrues: referral intake is closed at every other
     * status, so there is nothing to commission.
     */
    public function isCommissionEligible(): bool
    {
        return $this === self::Active;
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Inactive => 'gray',
            self::Suspended => 'yellow',
            self::Terminated => 'red',
        };
    }
}
