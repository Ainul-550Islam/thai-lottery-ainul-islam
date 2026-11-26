<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Enums\BetFundingSource;
use App\Exceptions\LotteryGameException;

/**
 * Stake x multiplier, in exact decimal arithmetic, with the free-bet policy applied.
 *
 * WHY THIS EXISTS SEPARATELY FROM SETTLEMENT
 * A bet's potential payout is needed at PLACEMENT time — to debit the number's
 * liability cap, to show the player a quote, and to freeze the amount that a later
 * cancellation must give back — and at SETTLEMENT time, when the winning lines are
 * paid. Both must use the same arithmetic or a quote and a payout will differ by a
 * cent. So the arithmetic lives here once, and the placement path and the
 * settlement adapter both call it.
 *
 * WHY bcmath AND NOT FLOATS
 * 10.00 x 90.5 is 905.00 exactly; a float pipeline can produce 904.9999999999999,
 * which rounds to 905.00 and hides the problem until a stake and a multiplier
 * conspire to land on a half-cent. Every operation here is bcmath at a fixed
 * scale and the result is rounded once, half-up, at the end — the repo's existing
 * amount convention.
 *
 * ROUNDING IS HALF-UP AND DELIBERATELY NOT PHP'S round()
 * round() is half-away-from-zero on the binary float representation, so
 * round(2.675, 2) is 2.67 in PHP, not 2.68. Add a cent to 2.675 with a scale-3
 * bcadd and then truncate at scale 2 and the half-up behaviour is exact and
 * driver-independent. Negative amounts are rounded away from zero symmetrically,
 * which is what a reversal needs.
 *
 * THE FREE-BET POLICY IS CONFIGURATION, NOT A CONSTANT
 * Whether a free-bet win returns the stake as well as the winnings is an operator
 * decision: the common convention is that it does not (the player never paid the
 * stake, so the multiplier minus one is what is added to their balance).
 * config('lottery_games.funding.free_bet_returns_stake') states it, and this
 * class reads it per call rather than caching it, so an operator's change takes
 * effect without a deploy.
 *
 * A MULTIPLIER THAT IS NOT A NUMBER IS NOT PAID
 * REQUIRED_INPUT, an empty string, "ninety" and a missing bet type all raise
 * game_not_configured. There is no default multiplier anywhere in this lane:
 * a payout path that guesses is a payout path that can invent money.
 *
 * NO WALLET, NO LEDGER, NO BALANCE
 * This class computes. Moving money is the existing wallet/ledger pipeline's job.
 */
final class PayoutCalculator
{
    /**
     * The payout a stake earns at a market's configured multiplier.
     *
     * @param  string  $stake  exact decimal string, e.g. "10.00"
     * @param  BetFundingSource  $fundingSource  real money unless stated
     *
     * @throws LotteryGameException game_not_configured when the market is unpriced
     */
    public function potentialPayout(
        GameDefinition $game,
        string $betType,
        string $stake,
        BetFundingSource $fundingSource = BetFundingSource::Real,
    ): string {
        $multiplier = $game->payoutMultiplierFor($betType);

        if ($multiplier === null) {
            // REQUIRED_INPUT in config resolves to null in GameDefinition, which is
            // exactly the collapse we want: unconfigured and unparseable are the
            // same refusal.
            throw LotteryGameException::notConfigured(
                $game->code,
                ['bet_types.'.$betType.'.payout_multiplier'],
                sprintf(
                    'Bet type "%s" of game "%s" has no payout_multiplier; nothing may be paid for it.',
                    $betType,
                    $game->code,
                ),
            );
        }

        if (! is_numeric($multiplier)) {
            throw LotteryGameException::notConfigured(
                $game->code,
                ['bet_types.'.$betType.'.payout_multiplier'],
                sprintf(
                    'Bet type "%s" of game "%s" has a non-numeric payout_multiplier.',
                    $betType,
                    $game->code,
                ),
            );
        }

        $effective = $this->effectiveMultiplier($multiplier, $fundingSource);

        // Scale 6 on the product so a fractional multiplier survives, then a single
        // half-up rounding at scale 2.
        return $this->roundMoney(bcmul($this->normaliseAmount($stake), $effective, 6));
    }

    /**
     * The multiplier actually applied to a stake, after the funding policy.
     *
     * Real money and bonus credit use the configured multiplier verbatim. A free
     * bet subtracts one from it unless configuration says the stake is returned,
     * and is floored at zero so a market priced at or below 1x never produces a
     * negative credit.
     *
     * The returned string keeps the multiplier's own precision ("900" stays "900",
     * "90.5" becomes "89.5") because the caller may store or compare it.
     */
    public function effectiveMultiplier(
        string $multiplier,
        BetFundingSource $fundingSource = BetFundingSource::Real,
    ): string {
        $multiplier = trim($multiplier);

        if ($fundingSource !== BetFundingSource::FreeBet || $this->freeBetReturnsStake()) {
            return $this->trimDecimal($multiplier);
        }

        $scale = $this->scaleOf($multiplier);
        $reduced = bcsub($multiplier, '1', $scale);

        // A 1x market returns exactly the stake, so a free bet on it pays nothing.
        // Negative is floored rather than returned: "-0.00" must never reach a
        // ledger, and neither must a negative credit.
        if (bccomp($reduced, '0', $scale) < 0) {
            return '0';
        }

        return $this->trimDecimal($reduced);
    }

    /**
     * Half-up rounding to the repo's two-decimal amount convention.
     *
     * Non-numeric input rounds to "0.00" rather than throwing: this method is
     * called at the end of arithmetic that has already validated its inputs, and a
     * money formatter that throws is worse than one that reports zero — the
     * validation happens upstream, where the reason can be explained.
     */
    public function roundMoney(string|int|float $amount): string
    {
        $value = $this->normaliseAmount($amount);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return '0.00';
        }

        // Half-up means AWAY FROM ZERO on both sides of zero, so the magnitude is
        // shifted by half a cent and then truncated at scale 2, and the sign is
        // reapplied afterwards. See the class docblock for why this is exact where
        // round() is not: -1.005 must become -1.01, not -1.00.
        $negative = str_starts_with($value, '-');
        $magnitude = $negative ? substr($value, 1) : $value;

        $rounded = bcadd(bcadd($magnitude, '0.005', 3), '0', 2);

        // "-0.00" is never a valid amount.
        if ($negative && bccomp($rounded, '0', 2) !== 0) {
            return '-'.$rounded;
        }

        return $rounded;
    }

    /**
     * The exact sum of a list of stake/multiplier lines.
     *
     * Each line is rounded individually before being added, because each line is
     * its own payout and the ledger posts cents, not fractions of a cent. Summing
     * unrounded lines and rounding once would produce a total that disagrees with
     * the sum of the credits actually paid.
     *
     * @param  list<array{stake: string|int|float, multiplier: string|int|float}>  $lines
     */
    public function totalPotentialPayout(array $lines): string
    {
        $total = '0.00';

        foreach ($lines as $line) {
            $stake = $this->normaliseAmount($line['stake'] ?? '0');
            $multiplier = $this->normaliseAmount($line['multiplier'] ?? '0');

            if (! is_numeric($stake) || ! is_numeric($multiplier)) {
                // An unpriceable line contributes nothing rather than a guess; the
                // caller's validation is responsible for refusing the bet.
                continue;
            }

            $total = bcadd($total, $this->roundMoney(bcmul($stake, $multiplier, 6)), 2);
        }

        return $total;
    }

    /**
     * Whether a stake is inside the configured bounds for this game.
     *
     * Returns null when it is, or a short machine-checkable reason when it is not,
     * so the placement path can raise one error code with one explanation.
     */
    public function stakeViolation(GameDefinition $game, string $stake): ?string
    {
        $amount = $this->roundMoney($stake);

        if (bccomp($amount, '0', 2) <= 0) {
            return 'stake_not_positive';
        }

        if ($game->minStake !== null && bccomp($amount, $game->minStake, 2) < 0) {
            return 'stake_below_minimum';
        }

        if ($game->maxStake !== null && bccomp($amount, $game->maxStake, 2) > 0) {
            return 'stake_above_maximum';
        }

        return null;
    }

    /**
     * The operator policy, read per call so a change needs no deploy.
     */
    private function freeBetReturnsStake(): bool
    {
        return (bool) config('lottery_games.funding.free_bet_returns_stake', false);
    }

    /**
     * Coerce an amount to a plain decimal string without exponent notation.
     *
     * Floats are formatted to six decimals rather than through (string), because
     * (string) on a float can emit "1.0E-5", which bcmath refuses.
     */
    private function normaliseAmount(string|int|float $amount): string
    {
        if (is_int($amount)) {
            return (string) $amount;
        }

        if (is_float($amount)) {
            return number_format($amount, 6, '.', '');
        }

        return trim($amount);
    }

    /**
     * The number of significant decimal places of a decimal string.
     *
     * "900" -> 0, "90.5" -> 1, "90.50" -> 1. Trailing zeros do not count, so the
     * scale used for an exact subtraction never introduces spurious precision that
     * trimDecimal() would then have to remove again.
     */
    private function scaleOf(string $value): int
    {
        $position = strpos($value, '.');

        if ($position === false) {
            return 0;
        }

        $fraction = rtrim(substr($value, $position + 1), '0');

        return strlen($fraction);
    }

    /**
     * Drop trailing zeros and a trailing point: "899.50" -> "899.5", "900.00" -> "900".
     */
    private function trimDecimal(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
