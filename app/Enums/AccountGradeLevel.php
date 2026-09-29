<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The account grade ladder (PROMPT 2, benchmark section A).
 *
 * SIX LEVELS, ONE FLOOR. Bronze is the base/no-grade state a player is
 * in below the first threshold - it always exists, is never advertised
 * as a programme tier, and entitles nothing. The five programme tiers
 * (SL 1..5) are the sellable ladder: gold_plus, platinum,
 * platinum_plus, diamond, diamond_plus.
 *
 * THE BENCHMARK LADDER REPLACES the retired silver/gold/platinum
 * schedule (rule_version '2' documents the change); historical
 * snapshot rows carrying old keys resolve defensively to the base
 * state, never to a fabricated tier.
 *
 * SL is the programme position used for ordering and "next tier"
 * resolution; the base state carries SL 0 so it sorts below the
 * ladder.
 */
enum AccountGradeLevel: string
{
    case Base = 'bronze';
    case GoldPlus = 'gold_plus';
    case Platinum = 'platinum';
    case PlatinumPlus = 'platinum_plus';
    case Diamond = 'diamond';
    case DiamondPlus = 'diamond_plus';

    /**
     * Programme position: 0 for the base state, 1..5 up the ladder.
     */
    public function sl(): int
    {
        return match ($this) {
            self::Base => 0,
            self::GoldPlus => 1,
            self::Platinum => 2,
            self::PlatinumPlus => 3,
            self::Diamond => 4,
            self::DiamondPlus => 5,
        };
    }

    /**
     * Is this the base/no-grade state?
     */
    public function isBase(): bool
    {
        return $this === self::Base;
    }

    /**
     * Resolve a stored grade key defensively: a historical or unknown
     * key resolves to the base state, so an old snapshot row can never
     * crash a live evaluation - and can never promote anybody either.
     */
    public static function fromKeyOrDefault(string $key): self
    {
        return self::tryFrom($key) ?? self::Base;
    }
}
