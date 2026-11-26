<?php

declare(strict_types=1);

namespace App\DTOs\LotteryGames;

/**
 * One validated digit selection, ready to be priced and placed.
 *
 * THE NUMBER IS A STRING, ALWAYS
 * '0042' and '42' are different selections in a 4-digit game, and a leading
 * zero lost here would silently sell a 3-digit number in a 4-digit market. No
 * accessor on this class returns an int, and nothing casts.
 *
 * The stake is an exact decimal string for the same reason in reverse: money is
 * never a float. Parsing into the repo's money value objects happens in
 * PayoutCalculator / the existing purchase pipeline, not here.
 */
final readonly class DigitBetSelection
{
    public function __construct(
        public string $gameCode,
        public string $betType,
        public string $number,
        public string $stake,
    ) {}

    /**
     * @return array{game_code: string, bet_type: string, number: string, stake: string}
     */
    public function toArray(): array
    {
        return [
            'game_code' => $this->gameCode,
            'bet_type' => $this->betType,
            'number' => $this->number,
            'stake' => $this->stake,
        ];
    }

    /**
     * A stable single-line identity for logging and idempotency comparison.
     */
    public function fingerprint(): string
    {
        return $this->gameCode.'|'.$this->betType.'|'.$this->number.'|'.$this->stake;
    }
}
