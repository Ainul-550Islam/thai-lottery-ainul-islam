<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\Enums\GameMatchMode;

/**
 * Does a selection win against a drawn string? Pure, total, and string-only.
 *
 * WHY THIS IS PURE
 * Whether a bet won is the single most disputed fact in a lottery. It must be
 * answerable without a database, without configuration, without a clock and
 * without money — so that a disputed payout can be re-derived from the two
 * strings an operator can see, and so that settlement can be replayed against a
 * historical draw without mutating anything. This class therefore has no
 * dependencies at all and evaluates exactly the arguments it is given.
 *
 * WHY IT RETURNS A DIAGNOSTIC AND NOT A BOOLEAN
 * A boolean cannot explain a dispute. evaluate() returns `won` plus a `reason`
 * from a closed vocabulary, and echoes the two inputs it compared. The reason is
 * what an operator reads, what the settlement audit row stores, and what the
 * tests assert on when a leading-zero case fails.
 *
 * THE REASON VOCABULARY
 *   matched                    the selection wins
 *   exact_mismatch             exact mode, strings differ
 *   multiset_mismatch          any_order mode, different digits or different counts
 *   prefix_mismatch            the drawn value does not start with the selection
 *   suffix_mismatch            the drawn value does not end with the selection
 *   selection_longer_than_draw the selection cannot fit inside the drawn value
 *   non_digit_input            one of the inputs is not all digits
 *   empty_selection            the selection is empty
 * Nothing else. A new mode must add its own reason, which is a review event.
 *
 * LEADING ZEROS
 * Both inputs are compared as strings with strlen/substr and per-character
 * multiset counts. There is no int cast in this class and no numeric comparison,
 * so "0042" and "42" are never equal. That is the entire point of the class.
 *
 * NO CONFIGURATION
 * The MODE comes from the caller, which reads it from config/lottery_games.php
 * via GameDefinition. This engine does not know which modes a game sells, and it
 * does not consult a payout multiplier: matching and pricing are separate
 * responsibilities, and this one has no money in it.
 */
final class DigitPrizeMatchingEngine
{
    /**
     * Compare one selection with one drawn value.
     *
     * @return array{
     *     won: bool,
     *     reason: string,
     *     mode: string,
     *     selection: string,
     *     drawn: string,
     *     arrangements: int|null
     * }
     */
    public function evaluate(GameMatchMode $mode, string $selection, string $drawn): array
    {
        $selection = trim($selection);
        $drawn = trim($drawn);

        $base = [
            'mode' => $mode->value,
            'selection' => $selection,
            'drawn' => $drawn,
            'arrangements' => null,
        ];

        // Refuse to "match" anything that is not a digit string. Two identical
        // "00a2" values are not a win: they are a broken payload, and treating
        // them as a match would pay a prize derived from nonsense.
        if ($selection === '' ) {
            return $base + ['won' => false, 'reason' => 'empty_selection'];
        }

        if (! ctype_digit($selection) || ! ctype_digit($drawn)) {
            return $base + ['won' => false, 'reason' => 'non_digit_input'];
        }

        // A selection can never win by being longer than the draw in any mode;
        // substring comparison would silently truncate it.
        if (strlen($selection) > strlen($drawn)) {
            return $base + ['won' => false, 'reason' => 'selection_longer_than_draw'];
        }

        return match ($mode) {
            GameMatchMode::Exact => $selection === $drawn
                ? $base + ['won' => true, 'reason' => 'matched']
                : $base + ['won' => false, 'reason' => 'exact_mismatch'],

            GameMatchMode::AnyOrder => $this->sameMultiset($selection, $drawn) && strlen($selection) === strlen($drawn)
                ? $base + ['won' => true, 'reason' => 'matched', 'arrangements' => $this->arrangements($selection)]
                : $base + ['won' => false, 'reason' => 'multiset_mismatch'],

            GameMatchMode::Prefix => str_starts_with($drawn, $selection)
                ? $base + ['won' => true, 'reason' => 'matched']
                : $base + ['won' => false, 'reason' => 'prefix_mismatch'],

            GameMatchMode::Suffix => str_ends_with($drawn, $selection)
                ? $base + ['won' => true, 'reason' => 'matched']
                : $base + ['won' => false, 'reason' => 'suffix_mismatch'],
        };
    }

    /**
     * Convenience boolean for callers that genuinely need only the outcome.
     */
    public function wins(GameMatchMode $mode, string $selection, string $drawn): bool
    {
        return $this->evaluate($mode, $selection, $drawn)['won'] === true;
    }

    /**
     * How many distinct arrangements a digit string has: n! divided by the
     * factorial of each repeated digit's count.
     *
     * WHY THIS IS PUBLIC
     * Two callers need exactly this number and must not compute it twice:
     * RtpAnalyzer, to price an any_order market's best case, and the admin rules
     * report, to explain why an any_order multiplier is affordable. Both call this
     * method, so a duplicated-digit market cannot be priced with one formula and
     * documented with another.
     *
     * "0000" -> 1, "0001" -> 4, "0012" -> 12, "0123" -> 24.
     *
     * Non-digit characters are counted as characters rather than refused: this is
     * a combinatorics helper and RtpAnalyzer only ever calls it with a configured
     * width, never with a player's string.
     */
    public function arrangements(string $digits): int
    {
        $digits = trim($digits);

        if ($digits === '') {
            return 0;
        }

        $counts = [];
        $length = strlen($digits);

        for ($i = 0; $i < $length; $i++) {
            $character = $digits[$i];
            $counts[$character] = ($counts[$character] ?? 0) + 1;
        }

        $total = $this->factorial($length);

        foreach ($counts as $count) {
            $total = intdiv($total, $this->factorial($count));
        }

        return $total;
    }

    /**
     * Whether two digit strings contain the same digits with the same
     * multiplicities.
     *
     * WHY NOT count_chars
     * count_chars() would have to be told which string to compare against and
     * returns a map that is easy to compare wrongly (missing keys read as zero).
     * An explicit multiset comparison is four lines, has no edge cases, and reads
     * as what it means.
     */
    private function sameMultiset(string $left, string $right): bool
    {
        if (strlen($left) !== strlen($right)) {
            return false;
        }

        $leftCounts = $this->digitCounts($left);
        $rightCounts = $this->digitCounts($right);

        ksort($leftCounts);
        ksort($rightCounts);

        return $leftCounts === $rightCounts;
    }

    /**
     * @return array<string, int>
     */
    private function digitCounts(string $digits): array
    {
        $counts = [];
        $length = strlen($digits);

        for ($i = 0; $i < $length; $i++) {
            $character = $digits[$i];
            $counts[$character] = ($counts[$character] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * n!, computed with integer division guard-free because n is a digit count
     * (bounded by configuration, single digits in practice).
     */
    private function factorial(int $n): int
    {
        if ($n <= 1) {
            return 1;
        }

        $result = 1;

        for ($i = 2; $i <= $n; $i++) {
            $result *= $i;
        }

        return $result;
    }
}
