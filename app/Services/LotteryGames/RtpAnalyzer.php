<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Enums\GameMatchMode;

/**
 * What a configured multiplier table actually pays back, computed — not trusted.
 *
 * WHY THIS IS PART OF THE FRAMEWORK AND NOT A SPREADSHEET
 * A digit game's economics are one line of arithmetic: probability of winning
 * times the multiplier. At 4 digits exact, one number in ten thousand, a 900x
 * multiplier returns 0.9 of stakes; at 9,500x it returns 0.95; at 12,000x it
 * returns 1.2 and the operator loses money on every bet sold, forever, with no
 * visible symptom until the draws come in. The operator supplies the multipliers,
 * so nothing in this lane may assume they are sane: this analyzer computes the
 * real RTP from the SAME configuration the payout path reads, and
 * lottery-games:validate-rules exits non-zero when any bet type exceeds the
 * ceiling in config('lottery_games.rtp_ceiling').
 *
 * HOW PROBABILITY IS DERIVED, PER MATCH MODE
 *   exact / prefix / suffix   one winning selection out of 10^d, where d is the
 *                             market's selection width. A two-digit suffix market
 *                             wins when the last two drawn digits match, so it is
 *                             1/100 whatever the draw's total width.
 *   any_order                 the BEST CASE for the player: every distinct
 *                             arrangement of the selection wins, so 10^d is
 *                             divided by the arrangement count of a selection of
 *                             d DISTINCT digits (d! for distinct digits). Using
 *                             the best case is deliberate: it is the RTP the
 *                             operator can actually be exposed to, and pricing on
 *                             the average would understate it.
 *
 * WHY THE RESULT CARRIES MORE THAN A BOOLEAN
 * An operator needs to see probability and RTP per bet type, not just "over the
 * ceiling", because the fix is always a specific multiplier. `breaches` names the
 * offending bet type so the command's output is actionable, and each entry is a
 * plain string so it can be printed, logged or shown in the admin report without
 * a second formatter.
 *
 * WHY THE CEILING IS A PARAMETER
 * The ceiling is an operator risk decision that lives in configuration. Taking it
 * as an argument keeps this class pure and testable at any ceiling (the tests
 * check 0.98 and a deliberately breached case), and keeps the single read of
 * config('lottery_games.rtp_ceiling') in the command that calls it.
 *
 * ALL ARITHMETIC IS STRING-BASED
 * Probabilities and RTPs are computed with bcmath at scale 8 and returned as
 * fixed-scale strings. A float RTP comparison against a ceiling is exactly the
 * kind of thing that passes at 0.9800000000001 and should not have.
 *
 * NO CONFIGURATION READ, NO DATABASE, NO MONEY MOVEMENT
 * This class computes what a table would pay; it never pays it.
 */
final class RtpAnalyzer
{
    /**
     * Every bet type's win probability and RTP, plus the ceiling verdict.
     *
     * @return array{
     *     game_code: string,
     *     draw_width: int|null,
     *     ceiling: string,
     *     bet_types: array<string, array{probability: string, multiplier: string|null, rtp: string|null, match_mode: string|null, selection_digits: int|null}>,
     *     within_ceiling: bool,
     *     breaches: list<string>
     * }
     */
    public function analyze(GameDefinition $game, string $ceiling): array
    {
        $ceiling = $this->normaliseDecimal($ceiling);
        $drawWidth = $game->resultDigitCount();
        $betTypes = [];
        $breaches = [];

        foreach ($game->betTypeKeys() as $betType) {
            $rule = $game->betTypes[$betType];
            $digits = $rule['selection_digits'];
            $mode = $rule['match_mode'];
            $multiplier = $rule['payout_multiplier'];

            $probability = $this->probabilityFor($mode, $digits, $drawWidth);

            $entry = [
                'probability' => $probability ?? '0.00000000',
                'multiplier' => $multiplier,
                'rtp' => null,
                'match_mode' => $mode?->value,
                'selection_digits' => $digits,
            ];

            if ($probability === null) {
                // An unpriced or unwidthed market cannot be analysed, and an
                // unanalysable market must never be reported as safe.
                $breaches[] = sprintf(
                    '%s: cannot be priced — %s',
                    $betType,
                    $digits === null || $digits <= 0
                        ? 'selection_digits is not configured'
                        : 'match_mode is not configured',
                );

                $betTypes[$betType] = $entry;

                continue;
            }

            if ($multiplier === null || ! is_numeric($multiplier)) {
                // REQUIRED_INPUT lands here. A game may not be sold on this market,
                // and the report says so instead of inventing a multiplier.
                $breaches[] = sprintf('%s: payout_multiplier is REQUIRED_INPUT or non-numeric', $betType);

                $betTypes[$betType] = $entry;

                continue;
            }

            $rtp = bcmul($probability, $this->normaliseDecimal($multiplier), 8);
            $entry['rtp'] = $rtp;
            $betTypes[$betType] = $entry;

            if (bccomp($rtp, $ceiling, 8) > 0) {
                $breaches[] = sprintf(
                    '%s: rtp %s exceeds the ceiling of %s',
                    $betType,
                    $rtp,
                    $ceiling,
                );
            }
        }

        return [
            'game_code' => $game->code,
            'draw_width' => $drawWidth,
            'ceiling' => $ceiling,
            'bet_types' => $betTypes,
            'within_ceiling' => $breaches === [],
            'breaches' => $breaches,
        ];
    }

    /**
     * The probability of one selection winning, as a fixed 8-decimal string, or
     * null when the market cannot be priced at all.
     */
    public function probabilityFor(?GameMatchMode $mode, ?int $selectionDigits, ?int $drawWidth): ?string
    {
        if ($mode === null || $selectionDigits === null || $selectionDigits <= 0) {
            return null;
        }

        if ($mode === GameMatchMode::AnyOrder) {
            // Best case for the player: every arrangement of DISTINCT digits wins.
            // The denominator is the draw's width when the market is as wide as the
            // draw (its usual shape), and never narrower than the selection.
            $width = max($selectionDigits, $drawWidth ?? $selectionDigits);

            $arrangements = $this->bestCaseArrangements($selectionDigits);
            $space = bcpow('10', (string) $width, 0);

            if (bccomp($space, '0', 0) <= 0) {
                return null;
            }

            return bcdiv((string) $arrangements, $space, 8);
        }

        // exact, prefix and suffix: exactly one winning selection out of 10^d.
        $space = bcpow('10', (string) $selectionDigits, 0);

        if (bccomp($space, '0', 0) <= 0) {
            return null;
        }

        return bcdiv('1', $space, 8);
    }

    /**
     * The arrangement count of a selection of n DISTINCT digits: n!.
     *
     * Delegated to the matching engine rather than recomputed, so a market that
     * repeats digits (which the best case must not assume) can never be priced
     * with a different formula than the one the matcher uses to count wins.
     */
    private function bestCaseArrangements(int $selectionDigits): int
    {
        $distinct = substr('0123456789', 0, max(1, $selectionDigits));

        return (new DigitPrizeMatchingEngine)->arrangements($distinct);
    }

    /**
     * A decimal string bcmath will accept: "0.98" stays, "0,98" or "" becomes 0.
     */
    private function normaliseDecimal(string $value): string
    {
        $value = trim($value);

        return preg_match('/^\d+(\.\d+)?$/', $value) === 1 ? $value : '0';
    }
}
