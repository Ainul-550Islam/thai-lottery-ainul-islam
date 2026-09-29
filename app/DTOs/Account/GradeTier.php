<?php

declare(strict_types=1);

namespace App\DTOs\Account;

use App\Enums\AccountGradeLevel;
use App\Enums\DiscountGame;

/**
 * ONE immutable row of the grade ladder (PROMPT 2, section A).
 *
 * A GradeTier is built exclusively by GradeTierCatalog from
 * config/account_grades.php - never constructed ad hoc - so a tier's
 * numbers can never disagree with the configuration the engine reads.
 *
 * CONTRACT
 * - min_spend and discount_rate are decimal STRINGS (THB platform
 *   currency units / fraction of one unit). No float ever touches a
 *   tier: a threshold compared as a float is a threshold that can flip
 *   on representation;
 * - thresholds are INCLUSIVE: qualifies() is >=, never >;
 * - the base tier (bronze) exists as the below-every-threshold state;
 * - eligible_games is the tier's ENTITLEMENT LIST in the canonical
 *   public-games order. It is the only game authority for grades: no
 *   page, policy or calculator invents its own list.
 */
final readonly class GradeTier
{
    /**
     * @param  list<DiscountGame>  $eligibleGames
     */
    public function __construct(
        public AccountGradeLevel $level,
        public int $sl,
        public string $key,
        public string $name,
        public string $minSpend,
        public string $discountRate,
        public array $eligibleGames,
        public string $icon,
        public bool $enabled,
        public bool $publicVisible,
        public ?string $effectiveFrom,
        public ?string $effectiveTo,
        public string $ruleVersion,
        public string $discountScope,
    ) {}

    /**
     * Is this the base/no-grade state?
     */
    public function isBase(): bool
    {
        return $this->level->isBase();
    }

    /**
     * Does a qualifying spend reach this tier? INCLUSIVE (>=), compared
     * as decimal strings with bcmath at money scale.
     */
    public function qualifies(string $spend): bool
    {
        return bccomp($spend, $this->minSpend, 2) >= 0;
    }

    /**
     * The whole-percent form of the rate fraction ('0.0200' -> '2.00'),
     * derived from the same fraction the engine applies. No suffix:
     * decoration belongs to the presentation layer, this is a number.
     */
    public function discountPercent(): string
    {
        return bcadd(bcmul($this->discountRate, '100', 2), '0', 2);
    }

    /**
     * Is this game entitled by this tier?
     */
    public function entitles(DiscountGame $game): bool
    {
        return in_array($game, $this->eligibleGames, true);
    }

    /**
     * Safe projection for logs/metadata.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'sl' => $this->sl,
            'level' => $this->level->value,
            'min_spend' => $this->minSpend,
            'discount_rate' => $this->discountRate,
            'discount_percent' => $this->discountPercent(),
            'icon' => $this->icon,
            'enabled' => $this->enabled,
            'public_visible' => $this->publicVisible,
            'eligible_games' => array_map(
                static fn (DiscountGame $game): string => $game->value,
                $this->eligibleGames,
            ),
            'effective_from' => $this->effectiveFrom,
            'effective_to' => $this->effectiveTo,
            'rule_version' => $this->ruleVersion,
            'discount_scope' => $this->discountScope,
        ];
    }
}
