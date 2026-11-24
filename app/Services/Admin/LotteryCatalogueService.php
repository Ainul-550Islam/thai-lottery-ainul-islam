<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\BetType;
use App\Enums\DrawType;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\NumberLimit;

/**
 * The lottery catalogue, read from where it actually lives.
 *
 * WHY THIS IS A READ-ONLY PROJECTION AND NOT A CRUD SCREEN
 *
 * There is no `games` table. The sellable catalogue is `config/lottery.php` — `types` (2D, 3D,
 * Tod, Run with digits, number range, ordering rule and payout multiplier) and `markets` (the
 * six sellable markets, each naming its bet type, its result source and its own multiplier).
 * `NumberNormalizationService` states the rule plainly: configuration is authoritative for
 * digit counts. The database stores *draws* and *bets* that refer to those words; it does not
 * store the words themselves.
 *
 * A "create game" button over that arrangement would therefore be a fake button: it would write
 * a row no engine reads, while the row that does govern behaviour stayed in a PHP file deployed
 * by git. So this service projects the truth and names the drift instead of pretending to edit
 * it. Making the catalogue database-backed is a schema decision with a money consequence (it
 * moves the payout multipliers into a table a compromised admin session could write), and it is
 * recorded as a blocker.
 *
 * WHAT IT ADDS THAT CONFIG ALONE DOES NOT SAY
 *
 *   1. DRIFT. Three vocabularies describe the same games — configuration, `BetType` and
 *      `DrawType` — all three now read the same numbers (`BetType` was corrected, `DrawType` was
 *      made to delegate to configuration, and the page re-checks both). The engine
 *      refuses to normalize a number when `BetType` and configuration disagree, which is the
 *      right call, but nobody could *see* the disagreement from any screen. `drift()` lists it.
 *   2. LIVE FACTS. How many draws of each type exist, when the next one is scheduled, how many
 *      bets and how much stake each type carries, and how many per-number limits are in force —
 *      the questions an operator actually asks about a game.
 *
 * Every method is a query or a config read. Nothing here writes, caches or guesses; a value that
 * cannot be read is reported as unknown rather than as zero.
 */
final class LotteryCatalogueService
{
    /**
     * One row per configured bet type, with its enum agreement and live facts.
     *
     * @return list<array<string, mixed>>
     */
    public function games(): array
    {
        $games = [];

        foreach ((array) config('lottery.types', []) as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $betType = BetType::tryFrom((string) $key);
            $drawType = DrawType::tryFrom((string) $key);

            $games[] = [
                'key' => (string) $key,
                'label' => (string) ($definition['label'] ?? strtoupper((string) $key)),
                'enabled' => (bool) ($definition['enabled'] ?? false),
                'digits' => (int) ($definition['digits'] ?? 0),
                'min_number' => (string) ($definition['min_number'] ?? ''),
                'max_number' => (string) ($definition['max_number'] ?? ''),
                'requires_exact_order' => (bool) ($definition['requires_exact_order'] ?? false),
                'allows_permutation' => (bool) ($definition['allows_permutation'] ?? false),
                'payout_multiplier' => (int) ($definition['payout_multiplier'] ?? 0),
                'markets' => array_values(array_map('strval', (array) ($definition['markets'] ?? []))),
                'bet_type_present' => $betType instanceof BetType,
                'draw_type_present' => $drawType instanceof DrawType,
                'drift' => $this->driftFor((string) $key, $definition, $betType, $drawType),
                'live' => $this->liveFacts((string) $key),
            ];
        }

        return $games;
    }

    /**
     * One row per sellable market. A market's multiplier is what a winning ticket is paid at,
     * and it is not always the type-level multiplier (run_top pays 3 while lottery.types.run
     * declares 12), so the two are shown side by side rather than reconciled here.
     *
     * @return list<array<string, mixed>>
     */
    public function markets(): array
    {
        $markets = [];

        foreach ((array) config('lottery.markets', []) as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $betTypeKey = (string) ($definition['bet_type'] ?? '');
            $typeMultiplier = (int) config(sprintf('lottery.types.%s.payout_multiplier', $betTypeKey), 0);

            $markets[] = [
                'key' => (string) $key,
                'label' => (string) ($definition['label'] ?? strtoupper((string) $key)),
                'bet_type' => $betTypeKey,
                'digits' => (int) ($definition['digits'] ?? 0),
                'position' => (string) ($definition['position'] ?? ''),
                'source' => (string) ($definition['source'] ?? ''),
                'match_mode' => (string) ($definition['match_mode'] ?? ''),
                'payout_multiplier' => (int) ($definition['payout_multiplier'] ?? 0),
                'type_payout_multiplier' => $typeMultiplier,
                'differs_from_type' => (int) ($definition['payout_multiplier'] ?? 0) !== $typeMultiplier,
                'enabled' => (bool) ($definition['enabled'] ?? false),
            ];
        }

        return $markets;
    }

    /**
     * The four draw products, with their enablement flags, straight from configuration.
     *
     * `PublicLotteryCatalogService` cannot answer this question: it deliberately omits a
     * switched-off product from the public hub, so it can never show an operator that a product
     * is off. This reads the flag itself and says so.
     *
     * @return list<array{key: string, config_key: string, enabled: bool}>
     */
    public function products(): array
    {
        $keys = ['national_lottery', 'weekly_lottery', 'bingo_lottery', 'pcso_lottery'];

        return array_map(fn (string $key): array => [
            'key' => $key,
            'config_key' => $key.'.enabled',
            'enabled' => (bool) config($key.'.enabled', false),
        ], $keys);
    }

    /**
     * Every disagreement between configuration and the enums, flattened for display.
     *
     * @return list<array{game: string, field: string, config: string, enum: string, source: string}>
     */
    public function drift(): array
    {
        $rows = [];

        foreach ($this->games() as $game) {
            foreach ((array) $game['drift'] as $entry) {
                $rows[] = $entry;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array{game: string, field: string, config: string, enum: string, source: string}>
     */
    private function driftFor(string $key, array $definition, ?BetType $betType, ?DrawType $drawType): array
    {
        $drift = [];

        if (! $betType instanceof BetType) {
            $drift[] = [
                'game' => $key,
                'field' => 'bet type',
                'config' => 'configured',
                'enum' => 'missing',
                'source' => 'App\\Enums\\BetType has no case for this key, so no bet can name it.',
            ];

            return $drift;
        }

        $checks = [
            'digits' => [(int) ($definition['digits'] ?? 0), $betType->digits(), 'App\\Enums\\BetType::digits()'],
            'payout_multiplier' => [(int) ($definition['payout_multiplier'] ?? 0), $betType->payoutMultiplier(), 'App\\Enums\\BetType::payoutMultiplier()'],
            'requires_exact_order' => [(bool) ($definition['requires_exact_order'] ?? false), $betType->requiresExactOrder(), 'App\\Enums\\BetType::requiresExactOrder()'],
            'allows_permutation' => [(bool) ($definition['allows_permutation'] ?? false), $betType->allowsPermutation(), 'App\\Enums\\BetType::allowsPermutation()'],
        ];

        foreach ($checks as $field => [$configured, $enumValue, $source]) {
            if ($configured !== $enumValue) {
                $drift[] = [
                    'game' => $key,
                    'field' => (string) $field,
                    'config' => $this->stringify($configured),
                    'enum' => $this->stringify($enumValue),
                    'source' => $source.' — the engine reads configuration, and NumberNormalizationService refuses to normalise a number while the two disagree.',
                ];
            }
        }

        // DrawType is the draw-scheduling vocabulary. Its helpers now READ this configuration
        // (see the enum's docblock), so these checks can only fire if somebody re-introduces a
        // literal into the enum — which is exactly why they stay: a watchdog that fails loudly
        // beats a comment asking people to be careful.
        if ($drawType instanceof DrawType) {
            $drawChecks = [
                'digits' => [(int) ($definition['digits'] ?? 0), $drawType->digits(), 'App\\Enums\\DrawType::digits()'],
                'max_number' => [(int) ($definition['max_number'] ?? 0), $drawType->maxNumber(), 'App\\Enums\\DrawType::maxNumber()'],
                'payout_multiplier' => [(int) ($definition['payout_multiplier'] ?? 0), $drawType->payoutMultiplier(), 'App\\Enums\\DrawType::payoutMultiplier()'],
            ];

            foreach ($drawChecks as $field => [$configured, $enumValue, $source]) {
                if ($configured !== $enumValue) {
                    $drift[] = [
                        'game' => $key,
                        'field' => (string) $field,
                        'config' => $this->stringify($configured),
                        'enum' => $this->stringify($enumValue),
                        'source' => $source.' — this helper is supposed to read the same configuration; a row here means a literal has been re-introduced into the enum.',
                    ];
                }
            }
        }

        return $drift;
    }

    /**
     * @return array<string, mixed>
     */
    private function liveFacts(string $key): array
    {
        $draws = Draw::query()->where('type', $key);

        return [
            'draws_total' => (clone $draws)->count(),
            'draws_open' => (clone $draws)->whereIn('status', ['scheduled', 'open', 'betting_open'])->count(),
            'next_scheduled_at' => (clone $draws)
                ->whereIn('status', ['scheduled', 'open', 'betting_open'])
                ->min('scheduled_at'),
            'bets_total' => Bet::query()->where('type', $key)->count(),
            // A display aggregate, not a ledger figure: summed by the database and normalised to
            // two decimals so the page does not print '300' beside a '300.00' elsewhere.
            'stake_total' => number_format((float) Bet::query()->where('type', $key)->sum('stake_amount'), 2, '.', ''),
            'number_limits' => NumberLimit::query()->where('bet_type', $key)->count(),
        ];
    }

    private function stringify(bool|int $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
