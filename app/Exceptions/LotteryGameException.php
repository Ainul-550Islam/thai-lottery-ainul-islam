<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The single exception type of the batch-2 digit-game lane.
 *
 * WHY ONE TYPE AND NOT SEVEN
 * Every refusal in this lane reaches an API client as a JSON body with an
 * `error.code` string, and reaches an operator as a log line. Those two needs are
 * the same need: a STABLE machine code plus the context that explains it. Seven
 * exception subclasses would mean seven places to remember when adding a code,
 * and the HTTP layer would still have to map each one to a status. One type that
 * carries its own code and context, mapped once by RendersGameResponses, cannot
 * drift that way.
 *
 * THE CODE IS THE CONTRACT
 * Codes are snake_case, stable, and never contain a message: `game_unknown`,
 * `game_not_configured`, `game_number_invalid`, `game_result_payload_invalid`,
 * `game_not_sellable`, `game_liability_exceeded`, `game_funding_unavailable`,
 * `game_release_refused`, `game_settlement_refused`, `game_fixture_refused`.
 * Tests and clients assert on the code, never on English prose. The message is
 * for humans and may be reworded.
 *
 * CONTEXT IS DATA, NOT A RENDERED STRING
 * context() returns the structured facts (game code, bet type, the missing
 * requirements list, the offending number) so callers can log, report or feed
 * them to an admin screen without parsing a message. `reason` is the one
 * human-readable member and it is also machine-checked in places ("expected 4
 * digits"), which is why factories set it explicitly rather than letting an
 * exception message double as data.
 */
final class LotteryGameException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The stable machine code. This is what the API returns and what tests assert.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * The structured facts behind the refusal.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * The game the refusal is about, when the caller knew it.
     */
    public function gameCode(): ?string
    {
        $code = $this->context['game_code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * A game code that no operator record and no configuration entry declares.
     */
    public static function unknownGame(string $code): self
    {
        return new self(
            'game_unknown',
            sprintf('Game "%s" is not registered.', $code),
            ['game_code' => $code],
        );
    }

    /**
     * The game exists but is not configured well enough to act on: a required
     * value is still REQUIRED_INPUT, or a gate has not passed.
     *
     * FAIL CLOSED: this is the exception every unverified game raises, and it is
     * raised instead of selling, scheduling, ingesting or paying.
     *
     * @param  list<string>  $missing  dotted paths an operator must fill
     */
    public static function notConfigured(string $code, array $missing = [], ?string $reason = null): self
    {
        $message = $reason ?? sprintf('Game "%s" is not configured.', $code);

        if ($missing !== []) {
            $message = sprintf('Game "%s" is not configured: %s.', $code, implode(', ', array_slice($missing, 0, 8)));
        }

        return new self(
            'game_not_configured',
            $message,
            ['game_code' => $code, 'missing' => array_values($missing), 'reason' => $message],
        );
    }

    /**
     * A player's selection does not fit the market it was submitted for.
     *
     * The reason string is machine-checked in tests and always names the
     * expectation ("expected 4 digits", "non-digit characters"), because a player
     * support conversation is about exactly that sentence.
     *
     * @param  array<string, mixed>  $context
     */
    public static function invalidNumber(string $reason, array $context = []): self
    {
        return new self(
            'game_number_invalid',
            $reason,
            $context + ['reason' => $reason],
        );
    }

    /**
     * A DRAWN result from a provider does not fit the game.
     *
     * Distinct from invalidNumber on purpose: a bad player selection is a 422 to
     * the player, a bad provider payload is a 503 to the caller and an operator
     * alert, because nothing the player did can fix it.
     *
     * @param  array<string, mixed>  $context
     */
    public static function invalidResultPayload(string $reason, array $context = []): self
    {
        return new self(
            'game_result_payload_invalid',
            $reason,
            $context + ['reason' => $reason],
        );
    }

    /**
     * No PUBLISHED result exists for the query.
     *
     * A distinct code from game_not_sellable on purpose: this is a 404 about data that
     * does not exist yet, not a 409 about a game whose state refuses a sale. A client
     * polls a result feed and stops asking about a game code that is simply wrong.
     */
    public static function resultUnavailable(string $gameCode, ?string $drawNo = null): self
    {
        return new self(
            'game_result_unavailable',
            sprintf(
                'No published result is available for game "%s"%s.',
                $gameCode,
                $drawNo === null ? '' : ' draw "'.$drawNo.'"',
            ),
            ['game_code' => $gameCode, 'draw_no' => $drawNo, 'reason' => 'no published result yet'],
        );
    }

    /**
     * The configured publication bridge names an instruction this lane cannot
     * apply without inventing digits.
     *
     * @param  array<string, mixed>  $context
     */
    public static function invalidInstruction(string $gameCode, string $instruction, int $digits): self
    {
        return new self(
            'game_publication_bridge_invalid',
            sprintf(
                'publication_bridge instruction "%s" cannot be applied to %d drawn digits: '
                .'the boundary accepts a six-digit first prize and a two-digit bottom two, '
                .'and no padding or truncation may be invented.',
                $instruction,
                $digits,
            ),
            [
                'game_code' => $gameCode,
                'instruction' => $instruction,
                'digits' => $digits,
                'reason' => 'publication_bridge_invalid',
            ],
        );
    }

    /**
     * The game or its draw is not open for business (no open draw, master switch
     * off, rules suspended, real-money lane closed).
     *
     * @param  array<string, mixed>  $context
     */
    public static function notSellable(string $reason, array $context = []): self
    {
        return new self(
            'game_not_sellable',
            $reason,
            $context + ['reason' => $reason],
        );
    }

    /**
     * The bet would breach a configured liability cap.
     *
     * @param  array<string, mixed>  $context
     */
    public static function liabilityExceeded(string $reason, array $context = []): self
    {
        return new self(
            'game_liability_exceeded',
            $reason,
            $context + ['reason' => $reason],
        );
    }

    /**
     * The stake cannot be funded: no voucher, no voucher provider configured, or a
     * funding source that this deployment cannot honour.
     *
     * @param  array<string, mixed>  $context
     */
    public static function fundingUnavailable(string $reason, array $context = []): self
    {
        return new self(
            'game_funding_unavailable',
            $reason,
            $context + ['reason' => $reason],
        );
    }

    /**
     * Releasing reserved exposure refused (a reconciliation was impossible).
     *
     * @param  array<string, mixed>  $context
     */
    public static function releaseRefused(string $reason, array $context = []): self
    {
        return new self(
            'game_release_refused',
            $reason,
            $context + ['reason' => $reason],
        );
    }

    /**
     * Settlement through the EXISTING pipeline refused or returned an unexpected
     * shape. Never swallowed: an unsettled draw is money owed.
     *
     * @param  array<string, mixed>  $context
     */
    public static function settlementRefused(string $reason, array $context = []): self
    {
        return new self(
            'game_settlement_refused',
            $reason,
            $context + ['reason' => $reason],
        );
    }

    /**
     * The development fixture result provider was asked for a result in an
     * environment where fabricating results is not allowed.
     *
     * @param  array<string, mixed>  $context
     */
    public static function fixtureRefused(string $reason, array $context = []): self
    {
        return new self(
            'game_fixture_refused',
            $reason,
            $context + ['reason' => $reason],
        );
    }
}
