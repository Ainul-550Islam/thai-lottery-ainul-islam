<?php

declare(strict_types=1);

namespace App\DTOs\Lottery;

use App\Enums\DiscountGame;
use App\Enums\DiscountLottery;

/**
 * The immutable, loaded-and-validated canonical matrix
 * (PROMPT 2, section F).
 *
 * Built exclusively by CanonicalDiscountMatrixService after load-time
 * validation has passed: every public game appears exactly once, in
 * its correct lottery family, with well-formed modes and a discount
 * that is either a bounded percentage or deliberately absent.
 *
 * LOOKUPS ARE PLAIN. Effectiveness filtering happens in the service
 * before this object is built; the DTO itself only answers "what is
 * the published rule for this game".
 */
final readonly class DiscountMatrix
{
    /**
     * @param  array<string, list<DiscountRule>>  $rulesByLottery  lottery key => rules in canonical game order
     * @param  array<string, string>  $affiliateCommission  lottery key => whole-percent decimal string
     */
    public function __construct(
        public array $rulesByLottery,
        public array $affiliateCommission,
        public string $ruleVersion,
        public string $currency,
        public string $baseStake,
    ) {}

    /**
     * The published rules of one lottery family, in canonical order.
     *
     * @return list<DiscountRule>
     */
    public function rules(DiscountLottery $lottery): array
    {
        return $this->rulesByLottery[$lottery->value] ?? [];
    }

    /**
     * The published rule for one game, or null when the game has no
     * effective row in the matrix.
     */
    public function ruleFor(DiscountGame $game): ?DiscountRule
    {
        foreach ($this->rules($game->lottery()) as $rule) {
            if ($rule->game === $game) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Every published rule across both families, canonical order.
     *
     * @return list<DiscountRule>
     */
    public function allRules(): array
    {
        $all = [];

        foreach (DiscountLottery::cases() as $family) {
            foreach ($this->rules($family) as $rule) {
                $all[] = $rule;
            }
        }

        return $all;
    }
}
