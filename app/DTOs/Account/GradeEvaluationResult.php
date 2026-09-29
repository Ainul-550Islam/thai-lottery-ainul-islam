<?php

declare(strict_types=1);

namespace App\DTOs\Account;

use App\Enums\AccountGradeLevel;
use App\Enums\DiscountGame;

/**
 * The immutable result of one server-side grade evaluation, produced
 * exclusively by AccountGradeEvaluator.
 *
 * CONTRACT
 * - every monetary member is a decimal STRING produced by bcmath;
 * - the evaluation never mutated any balance: this object is pure
 *   evidence, not a transaction;
 * - the result is deterministic for identical (source data, rule
 *   version, evaluation window): the entitlement hash is stable so
 *   snapshots can be compared and deduplicated;
 * - nothing here is client-derived: the caller supplies only a user and
 *   an optional asOf instant.
 */
final readonly class GradeEvaluationResult
{
    public function __construct(
        public int $userId,
        public string $windowStart,
        public string $windowEnd,
        public int $windowDays,
        public string $qualifyingSpend,
        public AccountGradeLevel $level,
        public GradeTier $tier,
        public ?AccountGradeLevel $previousLevel,
        public string $appliedRate,
        /** @var list<DiscountGame> */
        public array $eligibleGames,
        public string $entitlementHash,
        public string $ruleVersion,
        public string $evaluatedAt,
        public string $sourceVersion,
    ) {}

    /**
     * Spend still required to reach the next tier ('0.00' at the top).
     *
     * Computed by exact subtraction against the supplied next tier — the
     * evaluator passes the catalog's next tier so this stays a projection
     * with no config access of its own.
     */
    public function spendRemainingToNext(?GradeTier $next): string
    {
        if ($next === null) {
            return '0.00';
        }

        $remaining = bcsub($next->minSpend, $this->qualifyingSpend, 2);

        return bccomp($remaining, '0', 2) > 0 ? $remaining : '0.00';
    }

    /**
     * Is the resolved grade one of the five public programme tiers?
     */
    public function isGraded(): bool
    {
        return ! $this->level->isBase();
    }

    /**
     * Safe projection for the authenticated user's own grade surfaces.
     * (Public pages use the tier catalogue, never this per-user object.)
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'window_start' => $this->windowStart,
            'window_end' => $this->windowEnd,
            'window_days' => $this->windowDays,
            'qualifying_spend' => $this->qualifyingSpend,
            'grade_key' => $this->level->value,
            'grade_name' => $this->tier->name,
            'previous_grade_key' => $this->previousLevel?->value,
            'applied_rate' => $this->appliedRate,
            'eligible_games' => array_map(
                static fn (DiscountGame $game): string => $game->value,
                $this->eligibleGames,
            ),
            'entitlement_hash' => $this->entitlementHash,
            'rule_version' => $this->ruleVersion,
            'evaluated_at' => $this->evaluatedAt,
            'source_version' => $this->sourceVersion,
            'is_graded' => $this->isGraded(),
        ];
    }
}
