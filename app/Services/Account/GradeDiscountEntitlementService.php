<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\DTOs\Account\GradeDiscountEntitlement;
use App\DTOs\Account\GradeTier;
use App\Enums\AccountGradeLevel;
use App\Enums\DiscountGame;

/**
 * THE authority for grade→game entitlements (PROMPT 2, section B).
 *
 * This is the only place that answers "does grade X discount game Y".
 * The answer comes from the tier's canonical entitlement list - never
 * from a page, a policy or a client. Three properties matter:
 *
 * EXPLICITNESS. Every (tier, public game) pair gets an answer, and the
 * answer is a state: ELIGIBLE with the tier's rate, or NOT_ELIGIBLE
 * with an explicit zero. Nothing is left for a reader to infer.
 *
 * ORDER. eligibleGames() returns the tier's games in the canonical
 * public-games order (DiscountGame::publicGames()), so the API rows,
 * the panels and the entitlement hash all see the same sequence.
 *
 * STABILITY. The entitlement hash is a pure function of (level,
 * ordered games, rule version): identical inputs produce the identical
 * hash, so snapshots can be compared and deduplicated across runs.
 */
final class GradeDiscountEntitlementService
{
    public function __construct(
        private readonly GradeTierCatalog $catalog,
    ) {}

    /**
     * Is the game entitled by the tier?
     */
    public function isEligible(GradeTier $tier, DiscountGame $game): bool
    {
        return $tier->entitles($game);
    }

    /**
     * The explicit entitlement answer for one (tier, game) pair.
     */
    public function entitlementFor(GradeTier $tier, DiscountGame $game): GradeDiscountEntitlement
    {
        return $tier->entitles($game)
            ? GradeDiscountEntitlement::eligible($game, $tier)
            : GradeDiscountEntitlement::notEligible($game, $tier);
    }

    /**
     * The tier's entitled games, in canonical public-games order.
     *
     * @return list<DiscountGame>
     */
    public function eligibleGames(GradeTier $tier): array
    {
        return array_values(array_filter(
            DiscountGame::publicGames(),
            static fn (DiscountGame $game): bool => $tier->entitles($game),
        ));
    }

    /**
     * The stable entitlement hash: sha256 over the level, the ORDERED
     * game list and the rule version. Two evaluations of the same
     * (level, games, rule version) always hash identically, so an
     * unchanged entitlement set is recognisable as unchanged.
     */
    public function entitlementHash(GradeTier $tier): string
    {
        $games = array_map(
            static fn (DiscountGame $game): string => $game->value,
            $this->eligibleGames($tier),
        );

        return hash('sha256', implode('|', [
            $tier->level->value,
            implode(',', $games),
            $tier->ruleVersion,
        ]));
    }

    /**
     * Entitlement answer by level key, resolved through the catalogue.
     * Unknown/historical keys resolve to the base state, which entitles
     * nothing - an old snapshot key can never promote anybody.
     */
    public function entitlementForLevel(AccountGradeLevel $level, DiscountGame $game): GradeDiscountEntitlement
    {
        $tier = $this->catalog->tierByKey($level->value) ?? $this->catalog->baseTier();

        return $this->entitlementFor($tier, $game);
    }
}
