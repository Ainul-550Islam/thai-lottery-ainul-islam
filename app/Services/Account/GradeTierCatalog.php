<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\DTOs\Account\GradeTier;
use App\Enums\AccountGradeLevel;
use App\Enums\DiscountGame;
use InvalidArgumentException;
use Illuminate\Support\Carbon;

/**
 * THE authority for the grade ladder (PROMPT 2, section A).
 *
 * One catalogue, one truth. GradeTierCatalog is the ONLY thing that
 * turns config/account_grades.php into GradeTier objects; the public
 * ladder, the evaluator, the calculators and the snapshots all read
 * this class, so the advertised ladder and the applied ladder are the
 * same ladder.
 *
 * LOAD-TIME VALIDATION. A broken ladder is a configuration error that
 * must stop the surface, not render a wrong page:
 *   - exactly one base tier (bronze) with threshold 0.00 that entitles
 *     nothing;
 *   - every tier key known to AccountGradeLevel, no duplicates;
 *   - min_spend a non-negative 2-decimal string; discount_rate a
 *     4-decimal fraction between 0 and 1;
 *   - public tiers carry SL >= 1, strictly increasing, with strictly
 *     increasing thresholds - so "highest qualifying tier wins" is
 *     never ambiguous;
 *   - entitled game keys must be real DiscountGame cases.
 *
 * A configuration that violates any of these throws at load time: a
 * broken ladder must be a visible error, never a silently wrong page.
 */
final class GradeTierCatalog
{
    /** @var list<GradeTier>|null */
    private ?array $cache = null;

    /**
     * All tiers (base first, then public tiers by SL), effective at the
     * given instant. Base is always present — a user below every public
     * threshold is in the base state, which exists regardless of dates.
     *
     * @return list<GradeTier>
     */
    public function tiers(?\DateTimeInterface $asOf = null): array
    {
        return array_values(array_filter(
            $this->allTiers(),
            fn (GradeTier $tier): bool => $this->isEffective($tier, $asOf),
        ));
    }

    /**
     * The PUBLIC PROGRAMME: enabled, publicly visible, non-base tiers
     * in SL order. The base state is a real state, but it is not a
     * programme tier and is never advertised.
     *
     * @return list<GradeTier>
     */
    public function publicTiers(?\DateTimeInterface $asOf = null): array
    {
        return array_values(array_filter(
            $this->tiers($asOf),
            fn (GradeTier $tier): bool => $tier->publicVisible && ! $tier->isBase(),
        ));
    }

    /**
     * The base/no-grade tier.
     */
    public function baseTier(): GradeTier
    {
        foreach ($this->allTiers() as $tier) {
            if ($tier->isBase()) {
                return $tier;
            }
        }

        // Unreachable: loadTiers() guarantees exactly one base tier.
        throw new InvalidArgumentException('Grade catalogue has no base tier.');
    }

    /**
     * Resolve a qualifying spend to its tier.
     *
     * Thresholds are INCLUSIVE; below every public threshold the base
     * state is returned — never a fabricated filler tier.
     */
    public function tierForSpend(string $spend, ?\DateTimeInterface $asOf = null): GradeTier
    {
        $resolved = $this->baseTier();

        foreach ($this->tiers($asOf) as $tier) {
            if ($tier->isBase()) {
                continue;
            }

            if ($tier->enabled && $tier->qualifies($spend)) {
                $resolved = $tier; // ordered ascending → last match = highest
            }
        }

        return $resolved;
    }

    /**
     * The next public tier strictly above the given level (by SL), or
     * null at the top of the ladder.
     */
    public function nextTierAfter(AccountGradeLevel $level, ?\DateTimeInterface $asOf = null): ?GradeTier
    {
        foreach ($this->publicTiers($asOf) as $tier) {
            if ($tier->sl > $level->sl()) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * One tier by its stable key (any tier, public or base), or null.
     */
    public function tierByKey(string $key): ?GradeTier
    {
        foreach ($this->allTiers() as $tier) {
            if ($tier->key === $key) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * Effective-window predicate, shared convention with the fees and
     * discount engines: effective_from <= at AND (effective_to IS NULL OR
     * at < effective_to). effective_to is EXCLUSIVE.
     */
    public function isEffective(GradeTier $tier, ?\DateTimeInterface $at = null): bool
    {
        if ($tier->effectiveFrom === null && $tier->effectiveTo === null) {
            return true;
        }

        $now = $at !== null ? Carbon::instance($at) : now();

        if ($tier->effectiveFrom !== null && $now->lt(Carbon::parse($tier->effectiveFrom)->startOfDay())) {
            return false;
        }

        if ($tier->effectiveTo !== null && $now->gte(Carbon::parse($tier->effectiveTo)->startOfDay())) {
            return false;
        }

        return true;
    }

    /**
     * Load, validate and memoise the full catalogue.
     *
     * @return list<GradeTier>
     */
    private function allTiers(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $rows = config('account_grades.tiers');
        $rows = is_array($rows) ? array_values($rows) : [];

        if ($rows === []) {
            throw new InvalidArgumentException('The grade catalogue must define at least one tier.');
        }

        $tiers = [];
        $seen = [];
        $baseCount = 0;
        $lastPublic = null;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Every account grade tier must be an array.');
            }

            $key = (string) ($row['key'] ?? '');

            $level = AccountGradeLevel::tryFrom($key);
            if ($level === null) {
                throw new InvalidArgumentException('Unknown account grade tier key: '.$key);
            }

            if (isset($seen[$level->value])) {
                throw new InvalidArgumentException('Duplicate account grade tier key: '.$level->value);
            }
            $seen[$level->value] = true;

            // ---- threshold ------------------------------------------------
            $minSpend = (string) ($row['min_spend'] ?? '0.00');
            if (! preg_match('/^\d{1,13}(\.\d{1,2})?$/', $minSpend)) {
                throw new InvalidArgumentException('Tier '.$level->value.' has an invalid min_spend.');
            }

            // ---- rate fraction --------------------------------------------
            $rate = (string) ($row['discount_rate'] ?? '0.0000');
            if (! preg_match('/^\d{1,2}(\.\d{1,4})?$/', $rate)
                || bccomp($rate, '1.0000', 4) > 0) {
                throw new InvalidArgumentException('Tier '.$level->value.' has an invalid discount_rate fraction.');
            }

            // ---- entitlement list -----------------------------------------
            $games = [];
            foreach ((array) ($row['eligible_games'] ?? []) as $gameKey) {
                $game = DiscountGame::tryFrom((string) $gameKey);
                if ($game === null) {
                    throw new InvalidArgumentException('Tier '.$level->value.' entitles an unknown game: '.$gameKey);
                }
                $games[] = $game;
            }

            if ($level->isBase()) {
                ++$baseCount;

                if (bccomp($minSpend, '0.00', 2) !== 0) {
                    throw new InvalidArgumentException('The base tier threshold must be exactly 0.00.');
                }

                if ($games !== []) {
                    throw new InvalidArgumentException('The base/no-grade state cannot entitle any game.');
                }

                $tiers[] = new GradeTier(
                    level: $level,
                    sl: 0,
                    key: $level->value,
                    name: (string) ($row['name'] ?? 'Base'),
                    minSpend: '0.00',
                    discountRate: '0.0000',
                    eligibleGames: [],
                    icon: (string) ($row['icon'] ?? 'grade-base'),
                    enabled: (bool) ($row['enabled'] ?? true),
                    publicVisible: false,
                    effectiveFrom: null,
                    effectiveTo: null,
                    ruleVersion: (string) ($row['rule_version'] ?? config('account_grades.rule_version', '1')),
                    discountScope: (string) ($row['discount_scope'] ?? 'operator_markets'),
                );

                continue;
            }

            // ---- public programme tier --------------------------------------
            $sl = (int) ($row['sl'] ?? 0);
            if ($sl < 1) {
                throw new InvalidArgumentException('Public tier '.$level->value.' must have SL >= 1.');
            }

            if ($lastPublic !== null) {
                if ($sl <= $lastPublic->sl) {
                    throw new InvalidArgumentException('Public tier SL values must strictly increase.');
                }

                if (bccomp($minSpend, $lastPublic->minSpend, 2) <= 0) {
                    throw new InvalidArgumentException('Public tier thresholds must strictly increase — '.$level->value.' overlaps '.$lastPublic->key.'.');
                }
            }

            $lastPublic = new GradeTier(
                level: $level,
                sl: $sl,
                key: $level->value,
                name: (string) ($row['name'] ?? $level->value),
                minSpend: bcadd($minSpend, '0', 2),
                discountRate: bcadd($rate, '0', 4),
                eligibleGames: $games,
                icon: (string) ($row['icon'] ?? 'grade-'.$level->value),
                enabled: (bool) ($row['enabled'] ?? true),
                publicVisible: (bool) ($row['public_visible'] ?? false),
                effectiveFrom: isset($row['effective_from']) && is_string($row['effective_from']) && $row['effective_from'] !== '' ? $row['effective_from'] : null,
                effectiveTo: isset($row['effective_to']) && is_string($row['effective_to']) && $row['effective_to'] !== '' ? $row['effective_to'] : null,
                ruleVersion: (string) ($row['rule_version'] ?? config('account_grades.rule_version', '1')),
                discountScope: (string) ($row['discount_scope'] ?? 'operator_markets'),
            );

            $tiers[] = $lastPublic;
        }

        if ($baseCount !== 1) {
            throw new InvalidArgumentException('The catalogue must contain exactly one base tier.');
        }

        // Base first, then the programme by SL.
        usort($tiers, static fn (GradeTier $a, GradeTier $b): int => [$a->isBase() ? 0 : 1, $a->sl] <=> [$b->isBase() ? 0 : 1, $b->sl]);

        return $this->cache = $tiers;
    }
}
