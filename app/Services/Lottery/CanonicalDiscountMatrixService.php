<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\DTOs\Lottery\DiscountMatrix;
use App\DTOs\Lottery\DiscountRule;
use App\Enums\DiscountGame;
use App\Enums\DiscountLottery;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * THE authority for the canonical game/prize/discount matrix
 * (PROMPT 2, section F).
 *
 * One matrix, one truth. This service is the ONLY thing that turns
 * config/lotto_discount_matrix.php into DiscountRule/DiscountMatrix
 * objects; the public parity surface, the quote calculator and any
 * snapshot reader all consume it, so the published matrix and the
 * calculated matrix are the same matrix.
 *
 * IT IS A NEW CANONICAL GAME SPACE, not the live product lane. The
 * traced live products (operator_3d/2d/run, GLO) keep their existing
 * services and contracts; nothing here feeds the live bet economics.
 *
 * LOAD-TIME VALIDATION. A broken matrix is a configuration error that
 * must stop the surface, not render a wrong price:
 *   - every public game appears EXACTLY once, in its correct family -
 *     no missing game, no duplicate, no game filed under the wrong
 *     lottery, no unknown key;
 *   - the seven headline games publish BOTH 'D' and 'R'; every variant
 *     publishes exactly ONE '*' mode and never D/R;
 *   - a discount is a bounded percentage ('0.00'..'100.00') or NULL
 *     (NOT_CONFIGURED - never treated as zero, never guessed);
 *   - effective windows are non-inverted (from <= to, to exclusive);
 *   - multipliers are plain decimal strings over the published base
 *     stake.
 *
 * A configuration that violates any of these throws at load time.
 */
final class CanonicalDiscountMatrixService
{
    /** @var DiscountMatrix|null */
    private ?DiscountMatrix $cache = null;

    /**
     * The loaded matrix, filtered to the rows effective at the given
     * instant. Disabled/non-public rows are KEPT here (with their
     * flags) so callers can distinguish "not in the matrix" from "in
     * the matrix but not offered"; public projections and calculators
     * apply their own enabled/public gates.
     */
    public function matrix(?\DateTimeInterface $asOf = null): DiscountMatrix
    {
        $all = $this->loadMatrix();

        $rulesByLottery = [];

        foreach (DiscountLottery::cases() as $family) {
            $rules = [];

            foreach ($all->rules($family) as $rule) {
                if ($this->isEffective($rule, $asOf)) {
                    $rules[] = $rule;
                }
            }

            $rulesByLottery[$family->value] = $rules;
        }

        return new DiscountMatrix(
            rulesByLottery: $rulesByLottery,
            affiliateCommission: $all->affiliateCommission,
            ruleVersion: $all->ruleVersion,
            currency: $all->currency,
            baseStake: $all->baseStake,
        );
    }

    /**
     * The effective rule for one game, or null when the game has no
     * effective row. Callers decide how to treat disabled rows (the
     * calculator refuses them; projections skip them).
     */
    public function ruleFor(DiscountGame $game, ?\DateTimeInterface $asOf = null): ?DiscountRule
    {
        return $this->matrix($asOf)->ruleFor($game);
    }

    /**
     * Effective-window predicate, shared convention with the fees and
     * grade engines: effective_from <= at AND (effective_to IS NULL OR
     * at < effective_to). effective_to is EXCLUSIVE.
     */
    public function isEffective(DiscountRule $rule, ?\DateTimeInterface $at = null): bool
    {
        if ($rule->effectiveFrom === null && $rule->effectiveTo === null) {
            return true;
        }

        $now = $at !== null ? Carbon::instance($at) : now();

        if ($rule->effectiveFrom !== null && $now->lt(Carbon::parse($rule->effectiveFrom)->startOfDay())) {
            return false;
        }

        if ($rule->effectiveTo !== null && $now->gte(Carbon::parse($rule->effectiveTo)->startOfDay())) {
            return false;
        }

        return true;
    }

    /**
     * Load, validate and memoise the full matrix.
     */
    private function loadMatrix(): DiscountMatrix
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $ruleVersion = (string) config('lotto_discount_matrix.rule_version', '1');
        $currency = (string) config('lotto_discount_matrix.currency', 'THB');
        $baseStake = (string) config('lotto_discount_matrix.base_stake', '1.00');

        if (! preg_match('/^\d{1,13}(\.\d{1,2})?$/', $baseStake)) {
            throw new InvalidArgumentException('The discount matrix base stake must be a plain decimal amount.');
        }

        $commission = [];
        foreach ((array) config('lotto_discount_matrix.affiliate_commission', []) as $familyKey => $percent) {
            if (! is_string($percent) || ! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $percent)
                || bccomp($percent, '100.00', 2) > 0) {
                throw new InvalidArgumentException('The affiliate commission for '.$familyKey.' must be a bounded percentage.');
            }

            $commission[(string) $familyKey] = bcadd($percent, '0', 2);
        }

        $lotteries = config('lotto_discount_matrix.lotteries');
        $lotteries = is_array($lotteries) ? $lotteries : [];

        $rulesByLottery = [];
        $seenGames = [];

        foreach (DiscountLottery::cases() as $family) {
            $familyConfig = $lotteries[$family->value] ?? null;

            if (! is_array($familyConfig)) {
                throw new InvalidArgumentException('The discount matrix is missing the '.$family->value.' family.');
            }

            if (! isset($commission[$family->value])) {
                throw new InvalidArgumentException('The affiliate commission for '.$family->value.' is not published.');
            }

            $games = $familyConfig['games'] ?? [];
            $games = is_array($games) ? $games : [];

            $rules = [];

            foreach ($games as $gameKey => $row) {
                if (! is_array($row)) {
                    throw new InvalidArgumentException('Every matrix game row must be an array.');
                }

                $game = DiscountGame::tryFrom((string) $gameKey);
                if ($game === null) {
                    throw new InvalidArgumentException('Unknown matrix game key: '.$gameKey);
                }

                if (isset($seenGames[$game->value])) {
                    throw new InvalidArgumentException('Duplicate matrix game key: '.$game->value);
                }
                $seenGames[$game->value] = true;

                if ($game->lottery() !== $family) {
                    throw new InvalidArgumentException('Matrix game '.$game->value.' is filed under the wrong lottery.');
                }

                $rules[] = $this->buildRule($family, $game, $row, $ruleVersion, $baseStake, $currency);
            }

            $rulesByLottery[$family->value] = $rules;
        }

        // Every public game exactly once across the whole matrix.
        foreach (DiscountGame::publicGames() as $game) {
            if (! isset($seenGames[$game->value])) {
                throw new InvalidArgumentException('The matrix is missing the public game '.$game->value.'.');
            }
        }

        foreach (array_keys($lotteries) as $familyKey) {
            if (DiscountLottery::tryFrom((string) $familyKey) === null) {
                throw new InvalidArgumentException('Unknown matrix lottery family: '.$familyKey);
            }
        }

        return $this->cache = new DiscountMatrix(
            rulesByLottery: $rulesByLottery,
            affiliateCommission: $commission,
            ruleVersion: $ruleVersion,
            currency: $currency,
            baseStake: bcadd($baseStake, '0', 2),
        );
    }

    /**
     * Build and shape-check one rule row.
     *
     * @param  array<string, mixed>  $row
     */
    private function buildRule(DiscountLottery $family, DiscountGame $game, array $row, string $matrixRuleVersion, string $baseStake, string $currency): DiscountRule
    {
        $modes = $row['modes'] ?? [];
        $modes = is_array($modes) ? $modes : [];

        if ($modes === []) {
            throw new InvalidArgumentException('Matrix game '.$game->value.' publishes no win multiplier.');
        }

        foreach ($modes as $mode => $multiplier) {
            if (! is_string($multiplier) || ! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $multiplier)) {
                throw new InvalidArgumentException('Matrix game '.$game->value.' has an invalid multiplier for mode '.(string) $mode.'.');
            }
        }

        if ($game->hasDrawRegularModes()) {
            if (! array_key_exists(DiscountRule::MODE_DIRECT, $modes)
                || ! array_key_exists(DiscountRule::MODE_REVERSE, $modes)) {
                throw new InvalidArgumentException('Headline game '.$game->value.' must publish both D and R multipliers.');
            }
        } else {
            if (array_keys($modes) !== [DiscountRule::MODE_SINGLE]) {
                throw new InvalidArgumentException('Variant game '.$game->value.' must publish exactly one single multiplier.');
            }
        }

        $discount = $row['discount'] ?? null;

        if ($discount !== null
            && (! is_string($discount) || ! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $discount)
                || bccomp($discount, '100.00', 2) > 0)) {
            throw new InvalidArgumentException('Matrix game '.$game->value.' has an invalid discount percentage.');
        }

        $effectiveFrom = isset($row['effective_from']) && is_string($row['effective_from']) && $row['effective_from'] !== '' ? $row['effective_from'] : null;
        $effectiveTo = isset($row['effective_to']) && is_string($row['effective_to']) && $row['effective_to'] !== '' ? $row['effective_to'] : null;

        if ($effectiveFrom !== null && $effectiveTo !== null
            && Carbon::parse($effectiveFrom)->startOfDay()->gte(Carbon::parse($effectiveTo)->startOfDay())) {
            throw new InvalidArgumentException('Matrix game '.$game->value.' has an inverted effective window.');
        }

        return new DiscountRule(
            lottery: $family,
            game: $game,
            modes: $modes,
            discount: $discount !== null ? bcadd($discount, '0', 2) : null,
            baseStake: bcadd($baseStake, '0', 2),
            currency: $currency,
            ruleVersion: (string) ($row['rule_version'] ?? $matrixRuleVersion),
            enabled: (bool) ($row['enabled'] ?? true),
            publicVisible: (bool) ($row['public_visible'] ?? true),
            effectiveFrom: $effectiveFrom,
            effectiveTo: $effectiveTo,
        );
    }
}
