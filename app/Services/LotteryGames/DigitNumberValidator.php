<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Exceptions\LotteryGameException;

/**
 * The only place a digit selection or a drawn digit string is admitted.
 *
 * WHY A DEDICATED VALIDATOR
 * A digit game's entire integrity rests on "0042" never becoming 42. That single
 * mistake silently turns a 1-in-10,000 selection into a 1-in-100 one, pays the
 * wrong prize, and corrupts the exposure counter. It is therefore not spread
 * across a request class, a controller and a service as an inline regex; it is
 * one class, called from exactly two edges:
 *
 *   normalize()            a PLAYER's selection, before pricing, exposure or
 *                          purchase. A failure is the player's problem: 422.
 *   validateDrawnDigits()  a PROVIDER's drawn result, before staging. A failure
 *                          is not the player's problem: 503 plus an operator
 *                          alert, because nothing a player does can fix it.
 *
 * The two are separate methods because their failures have different audiences
 * and different HTTP meanings, not because the rules differ — both refuse rather
 * than repair.
 *
 * WHY NOTHING IS EVER PADDED OR TRUNCATED
 * "42" for a 4-digit market is refused, not padded to "0042". Padding would
 * guess which of the ten thousand numbers the player meant. Truncating a
 * provider's "0421X" would guess which digits were the draw. A refusal is
 * recoverable by a human; a guess is a payout.
 *
 * WHITESPACE IS THE ONE NORMALISATION
 * Selections arrive from forms, SMS bridges and CSV imports, so a surrounding
 * space or a trailing newline is an artefact of transport rather than a digit.
 * Trimming it is unambiguous; anything beyond that is not.
 *
 * THE LENGTH COMES FROM THE MARKET, NOT THE GAME
 * A game may sell a 4-digit exact market and a 2-digit suffix market at once, so
 * the expected width is read per bet type from GameDefinition (which reads it
 * from config/lottery_games.php). The drawn result, in contrast, is validated
 * against the game's DRAW width — GameDefinition::resultDigitCount() — because a
 * draw is one string for every market of that game.
 *
 * NO CURRENCY, NO MONEY, NO SIDE EFFECTS
 * This class reads and returns strings. It writes nothing, so it can be called
 * before a transaction opens.
 */
final class DigitNumberValidator
{
    /**
     * Admit and normalise a player's selection for one market of one game.
     *
     * Returns the selection exactly as it must be stored and compared: a string,
     * same digits, same order, same leading zeros.
     *
     * @throws LotteryGameException game_number_invalid
     */
    public function normalize(GameDefinition $game, string $betType, mixed $selection): string
    {
        $value = $this->asTrimmedString($selection);

        $expected = $game->selectionDigitsFor($betType);

        if (! $game->hasBetType($betType)) {
            throw LotteryGameException::invalidNumber(
                sprintf('Game "%s" does not sell bet type "%s".', $game->code, $betType),
                ['game_code' => $game->code, 'bet_type' => $betType, 'selection' => $value],
            );
        }

        if ($expected === null) {
            // The market exists but its width is not configured. That is an
            // operator omission, and failing closed here is the only honest
            // answer: guessing the width would price a bet nobody can settle.
            throw LotteryGameException::notConfigured(
                $game->code,
                ['bet_types.'.$betType.'.selection_digits'],
                sprintf('Bet type "%s" has no configured selection_digits.', $betType),
            );
        }

        if ($value === '') {
            throw LotteryGameException::invalidNumber(
                'A selection is required.',
                ['game_code' => $game->code, 'bet_type' => $betType, 'selection' => $value],
            );
        }

        if (! ctype_digit($value)) {
            throw LotteryGameException::invalidNumber(
                'A selection may contain digits only; non-digit characters were found.',
                ['game_code' => $game->code, 'bet_type' => $betType, 'selection' => $value, 'expected_digits' => $expected],
            );
        }

        if (strlen($value) !== $expected) {
            // Refused, never padded or truncated. See the class docblock.
            throw LotteryGameException::invalidNumber(
                sprintf('Selection length %d is wrong: expected %d digits.', strlen($value), $expected),
                ['game_code' => $game->code, 'bet_type' => $betType, 'selection' => $value, 'expected_digits' => $expected],
            );
        }

        return $value;
    }

    /**
     * Admit an official drawn result for a game.
     *
     * The result is the WHOLE drawn string: it is as wide as the game's widest
     * market, and shorter markets are matched inside it by prefix/suffix. It is
     * accepted verbatim — no trimming of internal characters, no reordering, no
     * zero-stripping — because those digits are what every player's bet is
     * compared against.
     *
     * @throws LotteryGameException game_result_payload_invalid
     */
    public function validateDrawnDigits(GameDefinition $game, mixed $digits): string
    {
        $value = $this->asTrimmedString($digits);

        $expected = $game->resultDigitCount();

        if ($value === '') {
            throw LotteryGameException::invalidResultPayload(
                'The result payload carries no digits.',
                ['game_code' => $game->code, 'digits' => $value],
            );
        }

        if (! ctype_digit($value)) {
            throw LotteryGameException::invalidResultPayload(
                'The result payload may contain digits only; non-digit characters were found.',
                ['game_code' => $game->code, 'digits' => $value],
            );
        }

        if ($expected === null || $expected <= 0) {
            throw LotteryGameException::notConfigured(
                $game->code,
                ['digits'],
                'The game declares no draw width, so no result can be accepted for it.',
            );
        }

        if (strlen($value) !== $expected) {
            // A shorter or longer draw would be matched against every market of
            // this game with the wrong width, so it is refused whole.
            throw LotteryGameException::invalidResultPayload(
                sprintf('Drawn digits have %d characters: expected %d.', strlen($value), $expected),
                ['game_code' => $game->code, 'digits' => $value, 'expected_digits' => $expected],
            );
        }

        return $value;
    }

    /**
     * Whether a selection would pass normalize(), without throwing.
     *
     * Offered for preview/quote endpoints and for the admin rules report, where a
     * boolean is wanted rather than an exception. It delegates rather than
     * re-implementing the rules, so the two can never disagree.
     */
    public function accepts(GameDefinition $game, string $betType, mixed $selection): bool
    {
        try {
            $this->normalize($game, $betType, $selection);

            return true;
        } catch (LotteryGameException) {
            return false;
        }
    }

    /**
     * The one normalisation: coerce to string, trim transport whitespace.
     *
     * A numeric input is accepted because JSON clients send 42 instead of "42",
     * but it is converted with a string cast BEFORE any comparison so a float
     * never enters the lane. A null becomes the empty string, which the callers
     * refuse with a reason rather than with a type error.
     */
    private function asTrimmedString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // A float selection is already suspect ("42.0"); refuse it here rather
            // than silently producing "42".
            return trim((string) $value) === (string) $value ? (string) $value : '';
        }

        return '';
    }
}
