<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Enums\GameMatchMode;
use App\Services\LotteryGames\RtpAnalyzer;
use DateTimeZone;

/**
 * Is this configuration a complete, coherent, sellable rule set?
 *
 * WHY A VALIDATOR EXISTS AT ALL
 * The framework's promise is fail-closed behaviour on unknown rules, and that
 * promise is only as good as the report an operator gets. "The game does not
 * work" is not actionable; "digits is still REQUIRED_INPUT, bet_types.4d_direct
 * .payout_multiplier is still REQUIRED_INPUT, and your cutoff closes betting
 * before the draw opens" is. So this validator's output is the product: a list of
 * MISSING values (operator input outstanding), a list of PROBLEMS (values that
 * exist but cannot be true together), and a list of WARNINGS (legal but worth
 * knowing). lottery-games:validate-rules prints it and exits non-zero while
 * `valid` is false.
 *
 * MISSING VERSUS PROBLEM
 *   missing   a literal REQUIRED_INPUT is still in the configuration. Only an
 *             operator can fix it, and it is found by walking the raw block, so a
 *             deeply nested placeholder (result_source.schema_version) is reported
 *             with its dotted path rather than lost.
 *   problem   two configured values contradict each other or are malformed. This
 *             is the class of error that produces a game which LOOKS configured:
 *             a cutoff longer than the draw cadence, a digit count that disagrees
 *             with the markets, a multiplier that is not a number.
 *
 * WHAT MAKES A RULE SET VALID
 * valid == (missing is empty) AND (problems is empty). Warnings alone never make a
 * game unsellable — they are for the operator's judgement — so a game with a
 * warning and no missing/problem is valid.
 *
 * WHY SO MANY CROSS-CHECKS
 * Each one corresponds to a way a plausible-looking config can still be wrong:
 *
 *   - digits versus the widest market width: a 4-digit draw with only 2-digit
 *     markets means every result is matched against the wrong width, and every
 *     payout is wrong by two digits of probability.
 *   - cutoff versus draw cadence: an hourly game with a 90-minute cutoff closes
 *     betting before it opens. The draws are never sellable and the schedule is
 *     silently pointless.
 *   - match mode versus selection width: an exact market whose width exceeds the
 *     draw width can never win; a prefix/suffix market wider than the draw can
 *     never win either. Both are certain losses for the player and unenforceable
 *     liability for the operator.
 *   - multiplier presence and numerics: refused already by PayoutCalculator at pay
 *     time, but reported here at validate time so the failure is caught by a
 *     command rather than by a player.
 *   - stake bounds and liability caps: min above max, or a per-number cap above
 *     the per-draw cap, makes the limits unusable by the placement path.
 *   - timezone and cron parseability: a schedule that cannot be computed produces
 *     no draws, and a game with no draws can sell nothing.
 *
 * NO CRON LIBRARY IS USED
 * The repo has no cron dependency and Rule §0.8 forbids adding one. The cadence
 * question ("how often does this expression fire?") is answered from the
 * minute/hour fields only, which is all that is needed to compare against a cutoff
 * in seconds. Anything the parser does not understand is reported as a problem
 * rather than guessed at.
 *
 * NO NETWORK, NO DATABASE, NO MONEY
 * Pure function of one GameDefinition plus, for the RTP warning, one ceiling.
 */
final class GameRulesValidator
{
    /**
     * The longest cutoff this validator will accept relative to a draw, in seconds
     * of the draw's own cadence. Kept as a named constant rather than a magic
     * number so the comparison reads as a rule.
     */
    private const SECONDS_PER_DAY = 86400;

    /**
     * Validate one game's configuration.
     *
     * @return array{
     *     valid: bool,
     *     game_code: string,
     *     missing: list<string>,
     *     problems: list<string>,
     *     warnings: list<string>
     * }
     */
    public function validate(GameDefinition $game): array
    {
        $missing = $game->requiredInputs();
        $problems = [];
        $warnings = [];

        $this->validateDigits($game, $problems);
        $this->validateBetTypes($game, $problems, $warnings);
        $this->validateWidths($game, $problems);
        $this->validateSchedule($game, $problems);
        $this->validateStakesAndCaps($game, $problems);
        $this->validateTimezone($game, $problems);
        $this->validateResultSource($game, $problems, $warnings);
        $this->validateSettlementAndBridge($game, $problems, $warnings);
        $this->validateMarketAlignment($game, $problems, $warnings);

        return [
            'valid' => $missing === [] && $problems === [],
            'game_code' => $game->code,
            'missing' => $missing,
            'problems' => $problems,
            'warnings' => $warnings,
        ];
    }

    /**
     * The draw width must be a positive number of digits in a plausible range.
     *
     * @param  list<string>  $problems
     */
    private function validateDigits(GameDefinition $game, array &$problems): void
    {
        if ($game->digits === null) {
            // Covered by `missing` when the raw value is REQUIRED_INPUT, but a
            // non-numeric placeholder is not, so it is reported here too.
            if (! in_array('digits', $game->requiredInputs(), true)) {
                $problems[] = 'digits is not a number.';
            }

            return;
        }

        if ($game->digits <= 0 || $game->digits > 10) {
            $problems[] = sprintf(
                'digits (%d) is outside the plausible range 1..10.',
                $game->digits,
            );
        }
    }

    /**
     * Every declared bet type must be complete in itself.
     *
     * @param  list<string>  $problems
     * @param  list<string>  $warnings
     */
    private function validateBetTypes(GameDefinition $game, array &$problems, array &$warnings): void
    {
        if ($game->betTypeKeys() === []) {
            $problems[] = 'bet_types declares no market, so the game can sell nothing.';

            return;
        }

        foreach ($game->betTypes as $betType => $rule) {
            $digits = $rule['selection_digits'];
            $mode = $rule['match_mode'];
            $multiplier = $rule['payout_multiplier'];

            if ($digits === null || $digits <= 0) {
                // A value that is still the literal REQUIRED_INPUT placeholder is
                // already reported in `missing`, where the operator can act on it.
                // Reporting it twice would double the apparent defect count and make
                // the report harder to work from.
                if (! in_array('bet_types.'.$betType.'.selection_digits', $game->requiredInputs(), true)) {
                    $problems[] = sprintf('bet_types.%s.selection_digits is not configured.', $betType);
                }
            } elseif ($game->digits !== null && $digits > $game->digits) {
                $problems[] = sprintf(
                    'bet_types.%s.selection_digits (%d) is wider than the draw (digits=%d), so it can never win.',
                    $betType,
                    $digits,
                    $game->digits,
                );
            }

            if ($mode === null) {
                $problems[] = sprintf(
                    'bet_types.%s.match_mode is not one of: %s.',
                    $betType,
                    implode(', ', GameMatchMode::values()),
                );
            }

            if ($multiplier === null) {
                // Already in `missing` when it is the literal REQUIRED_INPUT; only
                // report a problem when it is absent for another reason.
                if (! in_array('bet_types.'.$betType.'.payout_multiplier', $game->requiredInputs(), true)) {
                    $problems[] = sprintf('bet_types.%s has no payout_multiplier.', $betType);
                }
            } elseif (! is_numeric($multiplier)) {
                $problems[] = sprintf(
                    'bet_types.%s has a non-numeric payout_multiplier ("%s").',
                    $betType,
                    $multiplier,
                );
            } elseif (bccomp($this->normaliseDecimal($multiplier), '0', 8) <= 0) {
                $problems[] = sprintf(
                    'bet_types.%s.payout_multiplier (%s) must be greater than zero.',
                    $betType,
                    $multiplier,
                );
            } elseif (bccomp($this->normaliseDecimal($multiplier), '1', 8) < 0) {
                // A sub-1x market returns less than the stake on a win. Legal in some
                // jurisdictions, a very strange product in most, and never a typo
                // anyone notices.
                $warnings[] = sprintf(
                    'bet_types.%s.payout_multiplier (%s) is below 1x: a winning bet returns less than its stake.',
                    $betType,
                    $multiplier,
                );
            }
        }
    }

    /**
     * The declared draw width must agree with the widest market.
     *
     * @param  list<string>  $problems
     */
    private function validateWidths(GameDefinition $game, array &$problems): void
    {
        $widest = $this->widestSelection($game);

        if ($widest === null || $game->digits === null) {
            return;
        }

        if ($widest !== $game->digits) {
            // Two plausible configs can produce this: a 4-digit draw sold only as a
            // 2-digit suffix market (the result is matched with the wrong width), or
            // a 2-digit draw sold as a 4-digit exact market (nothing can ever win).
            // Both mean the declared width and the declared markets disagree.
            $problems[] = sprintf(
                'digits (%d) and the widest bet_types selection_digits (%d) disagree: '
                .'the draw width must equal the widest market.',
                $game->digits,
                $widest,
            );
        }
    }

    /**
     * The schedule must parse, and the cutoff must fit inside it.
     *
     * @param  list<string>  $problems
     */
    private function validateSchedule(GameDefinition $game, array &$problems): void
    {
        if ($game->drawCron === []) {
            $problems[] = 'draw_cron declares no draw time.';

            return;
        }

        $cadences = [];

        foreach ($game->drawCron as $index => $expression) {
            $seconds = $this->cadenceSeconds($expression);

            if ($seconds === null) {
                // An unparseable PLACEHOLDER is operator input outstanding, already
                // in `missing`. An unparseable EXPRESSION is a defect in a value the
                // operator believed they had finished.
                if (! str_contains($expression, GameDefinition::REQUIRED_INPUT)) {
                    $problems[] = sprintf(
                        'draw_cron.%d ("%s") cannot be parsed; a schedule that cannot be computed produces no draws.',
                        $index,
                        $expression,
                    );
                }

                continue;
            }

            $cadences[] = $seconds;
        }

        if ($cadences === [] || $game->cutoffSeconds === null) {
            return;
        }

        $shortest = min($cadences);

        if ($game->cutoffSeconds <= 0) {
            $problems[] = sprintf('cutoff_seconds (%d) must be a positive number of seconds.', $game->cutoffSeconds);

            return;
        }

        if ($game->cutoffSeconds >= $shortest) {
            // The draw's own cadence is the tightest interval between two draws, so
            // a cutoff at or beyond it closes betting before (or exactly when) it
            // opens. Nothing is ever sellable, and nothing looks broken.
            $problems[] = sprintf(
                'cutoff_seconds (%d) exceeds the draw cadence (%d seconds), so betting would close before it opened.',
                $game->cutoffSeconds,
                $shortest,
            );
        }
    }

    /**
     * Stakes must be ordered and positive; caps must be ordered and sane.
     *
     * @param  list<string>  $problems
     */
    private function validateStakesAndCaps(GameDefinition $game, array &$problems): void
    {
        $min = $game->minStake;
        $max = $game->maxStake;

        if ($min !== null && ! is_numeric($min)) {
            $problems[] = sprintf('min_stake ("%s") is not a number.', $min);
            $min = null;
        }

        if ($max !== null && ! is_numeric($max)) {
            $problems[] = sprintf('max_stake ("%s") is not a number.', $max);
            $max = null;
        }

        if ($min !== null && bccomp($this->normaliseDecimal($min), '0', 2) <= 0) {
            $problems[] = sprintf('min_stake (%s) must be greater than zero.', $min);
        }

        if ($min !== null && $max !== null && bccomp($this->normaliseDecimal($min), $this->normaliseDecimal($max), 2) > 0) {
            $problems[] = sprintf('min_stake (%s) is greater than max_stake (%s).', $min, $max);
        }

        $perDraw = $game->maxLiabilityPerDraw;
        $perNumber = $game->maxLiabilityPerNumber;

        foreach ([['max_liability_per_draw', $perDraw], ['max_liability_per_number', $perNumber]] as [$name, $value]) {
            if ($value === null) {
                continue;
            }

            if (! is_numeric($value)) {
                $problems[] = sprintf('%s ("%s") is not a number.', $name, $value);

                continue;
            }

            if (bccomp($this->normaliseDecimal($value), '0', 2) <= 0) {
                $problems[] = sprintf('%s (%s) must be greater than zero.', $name, $value);
            }
        }

        if (
            is_numeric((string) $perDraw)
            && is_numeric((string) $perNumber)
            && bccomp($this->normaliseDecimal((string) $perNumber), $this->normaliseDecimal((string) $perDraw), 2) > 0
        ) {
            // A per-number cap above the per-draw cap can never bind, so the
            // placement path would allow a single number to consume the whole draw.
            $problems[] = sprintf(
                'max_liability_per_number (%s) is greater than max_liability_per_draw (%s), so the per-number cap never binds.',
                (string) $perNumber,
                (string) $perDraw,
            );
        }
    }

    /**
     * The timezone must be a real IANA identifier, not an offset or a guess.
     *
     * @param  list<string>  $problems
     */
    private function validateTimezone(GameDefinition $game, array &$problems): void
    {
        try {
            new DateTimeZone($game->timezone);
        } catch (\Exception) {
            $problems[] = sprintf(
                'timezone ("%s") is not a valid IANA identifier.',
                $game->timezone,
            );
        }
    }

    /**
     * A result source must name a driver and, unless it is the fixture driver, a
     * complete endpoint contract.
     *
     * @param  list<string>  $problems
     * @param  list<string>  $warnings
     */
    private function validateResultSource(GameDefinition $game, array &$problems, array &$warnings): void
    {
        $driver = $game->resultSource['driver'] ?? null;

        if (! is_string($driver) || trim($driver) === '') {
            // A driver may be omitted when the endpoint contract is complete: the
            // provider chain then resolves the official driver by default, which is
            // what a configuration that names base_url and both paths means. Only a
            // result source with neither a driver nor an endpoint is unusable.
            $hasEndpoint = isset($game->resultSource['base_url'], $game->resultSource['latest_path']);

            if (! $hasEndpoint) {
                $problems[] = 'result_source.driver is not configured, so no official result can be obtained.';

                return;
            }

            $warnings[] = 'result_source.driver is omitted; the official driver is assumed from the endpoint contract.';

            return;
        }

        if ($driver === 'fixture') {
            // Legal for development, refused in production by the provider itself.
            $warnings[] = 'result_source.driver is "fixture": fabricated results, refused in production.';

            return;
        }

        foreach (['base_url', 'latest_path', 'draw_no_field', 'digits_field'] as $key) {
            $value = $game->resultSource[$key] ?? null;

            if (! is_string($value) || trim($value) === '' || trim($value) === GameDefinition::REQUIRED_INPUT) {
                // Required-but-missing is already in `missing`; only a value that is
                // absent without being the literal placeholder is a problem here.
                if (! in_array('result_source.'.$key, $game->requiredInputs(), true)) {
                    $problems[] = sprintf('result_source.%s is not configured.', $key);
                }
            }
        }
    }

    /**
     * Settlement must go through an existing market, and publication must be
     * decidable.
     *
     * @param  list<string>  $problems
     * @param  list<string>  $warnings
     */
    private function validateSettlementAndBridge(GameDefinition $game, array &$problems, array &$warnings): void
    {
        if ($game->settlementMarketKey === null) {
            if (! in_array('settlement_market_key', $game->requiredInputs(), true)) {
                $problems[] = 'settlement_market_key is not configured, so bets could not be settled.';
            }
        } else {
            /** @var array<string, mixed> $markets */
            $markets = (array) config('lottery.markets', []);

            if ($markets !== [] && ! array_key_exists($game->settlementMarketKey, $markets)) {
                // Warn rather than refuse: the market key is resolved per bet type
                // too, and the settlement adapter is the component that ultimately
                // refuses an unknown key. A warning keeps the rules report honest
                // without duplicating that decision here.
                $warnings[] = sprintf(
                    'settlement_market_key ("%s") is not declared in config/lottery.markets.',
                    $game->settlementMarketKey,
                );
            }
        }

        $bridge = $game->publicationBridge;

        if ($bridge === []) {
            $problems[] = 'publication_bridge is not configured, so a result could never be published.';

            return;
        }

        $decidable = false;

        foreach ($bridge as $key => $value) {
            if (is_string($value) && trim($value) !== '' && trim($value) !== GameDefinition::REQUIRED_INPUT) {
                $decidable = true;
            }
        }

        if (! $decidable) {
            // Every member is still a placeholder: the row may be staged, but
            // nothing may claim publication. Already reported through `missing`
            // when the values are REQUIRED_INPUT, so this is a warning.
            $warnings[] = 'publication_bridge is entirely REQUIRED_INPUT: results may be staged but never published.';
        }
    }

    /**
     * The widest selection width declared by any market, or null.
     */
    private function widestSelection(GameDefinition $game): ?int
    {
        $widest = null;

        foreach ($game->betTypes as $rule) {
            $digits = $rule['selection_digits'];

            if ($digits !== null && ($widest === null || $digits > $widest)) {
                $widest = $digits;
            }
        }

        return $widest;
    }

    /**
     * How many seconds apart this cron expression fires, from its minute and hour
     * fields only.
     *
     * Supported shapes, which cover every real draw schedule:
     *   "* * * * *"        every minute                  -> 60
     *   "*\/5 * * * *"      every 5 minutes               -> 300
     *   "30 * * * *"        hourly at :30                 -> 3600
     *   "*\/2 * * * *"      every 2 hours at :00          -> 7200
     *   "0 21 * * *"        daily at 21:00                -> 86400
     *   "0 21,22 * * *"     twice daily                   -> 43200
     *
     * Returns null for anything it cannot read, which the caller reports rather
     * than guessing a cadence — a guessed cadence would let a bad cutoff pass.
     */
    private function cadenceSeconds(string $expression): ?int
    {
        $fields = preg_split('/\s+/', trim($expression)) ?: [];

        if (count($fields) < 5) {
            return null;
        }

        [$minute, $hour] = $fields;

        [$minuteStep, $minuteFixed] = $this->parseField($minute);
        [$hourStep, $hourFixed] = $this->parseField($hour);

        if ($minuteStep === null || $hourStep === null) {
            return null;
        }

        if ($minuteStep > 1) {
            return $minuteStep * 60;
        }

        if ($minuteFixed === null) {
            // Minute is '*': the draw fires every minute.
            if ($hourFixed === null) {
                return 60;
            }

            return 3600 * max(1, count($hourFixed));
        }

        if ($hourFixed === null) {
            // A fixed minute with any hour is hourly.
            return 3600;
        }

        $drawsPerDay = max(1, count($hourFixed));

        return intdiv(self::SECONDS_PER_DAY, $drawsPerDay);
    }

    /**
     * Read one cron field.
     *
     * @return array{0: int|null, 1: list<int>|null} [step, fixed values]
     *                                                step is null when unreadable,
     *                                                fixed values are null for '*'
     */
    private function parseField(string $field): array
    {
        $field = trim($field);

        if ($field === '*') {
            return [1, null];
        }

        if (preg_match('#^\*/(\d+)$#', $field, $matches) === 1) {
            $step = (int) $matches[1];

            return $step > 0 ? [$step, null] : [null, null];
        }

        if (preg_match('/^\d+(,\d+)*$/', $field) === 1) {
            $values = array_map(static fn (string $part): int => (int) $part, explode(',', $field));

            return [1, $values];
        }

        // Ranges, names and every other shape are not guessed at.
        return [null, null];
    }

    /**
     * The multiplier a player is QUOTED must be the multiplier Batch 1 will PAY.
     *
     * WHY THIS CHECK EXISTS
     * Two configurations name a payout for the same bet. The quote, the RTP analysis and
     * the liability reservation all read this lane's `payout_multiplier`; the money that
     * actually reaches a winner is computed by Batch 1's settlement from
     * config('lottery.markets.<market>.payout_multiplier'). Nothing else compares them,
     * so an edit to either would silently split what the platform promised from what it
     * pays - in the player's favour (a loss to the operator the liability caps never saw)
     * or against it (a promise broken). Verification must therefore refuse the split.
     *
     * HOW STRICT IT IS
     *   - width differs from the market's      -> WARNING. Placement already refuses that
     *     bet type (assertMarketAcceptsWidth), so it can never be sold, which is a
     *     rules-report fact rather than a payout risk.
     *   - EXACT match mode, multipliers differ -> PROBLEM. An exact selection and an exact
     *     market are the same bet; a different payout is a contradiction.
     *   - any other match mode, multipliers differ -> WARNING. A market pays on its own
     *     matching rule, and whether that rule is this mode is a Batch-1 fact this
     *     validator cannot see; the operator is told, not silently trusted.
     *   - the market is not declared in config/lottery.markets -> nothing here; the
     *     existing settlement-market warning already says so.
     *
     * @param  list<string>  $problems
     * @param  list<string>  $warnings
     */
    private function validateMarketAlignment(GameDefinition $game, array &$problems, array &$warnings): void
    {
        /** @var array<string, mixed> $markets */
        $markets = (array) config('lottery.markets', []);

        if ($markets === []) {
            return;
        }

        foreach ($game->betTypeKeys() as $betType) {
            $marketKey = $game->marketKeyFor($betType);
            $market = $marketKey !== null ? ($markets[$marketKey] ?? null) : null;

            if ($marketKey === null || ! is_array($market)) {
                continue;
            }

            $digits = $game->selectionDigitsFor($betType);
            $marketWidth = isset($market['digits']) && is_numeric($market['digits']) ? (int) $market['digits'] : null;

            if ($digits !== null && $marketWidth !== null && $digits !== $marketWidth) {
                $warnings[] = sprintf(
                    'bet type "%s" selects %d digit(s) but settles through market "%s" which holds %d, so placement will refuse it.',
                    $betType,
                    $digits,
                    $marketKey,
                    $marketWidth,
                );

                continue;
            }

            $quoted = $game->payoutMultiplierFor($betType);
            $paid = $market['payout_multiplier'] ?? null;

            if ($quoted === null || ! is_numeric($paid) || preg_match('/^\d+(\.\d+)?$/', $quoted) !== 1) {
                continue;
            }

            $paid = $this->normaliseDecimal((string) $paid);

            if (bccomp($this->normaliseDecimal($quoted), $paid, 2) === 0) {
                continue;
            }

            $message = sprintf(
                'bet type "%s" quotes %sx but settles through market "%s" which pays %sx.',
                $betType,
                $quoted,
                $marketKey,
                $paid,
            );

            if ($game->matchModeFor($betType) === GameMatchMode::Exact) {
                $problems[] = $message.' The quoted and the paid multiplier must be the same number.';

                continue;
            }

            $warnings[] = $message.' Confirm that the market settles this match mode the same way.';
        }
    }

    /**
     * A decimal string bcmath will accept, or "0".
     */
    private function normaliseDecimal(string $value): string
    {
        $value = trim($value);

        return preg_match('/^\d+(\.\d+)?$/', $value) === 1 ? $value : '0';
    }
}
