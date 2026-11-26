<?php

declare(strict_types=1);

namespace App\DTOs\LotteryGames;

/**
 * One official drawn digit string, as fetched from a provider.
 *
 * It is a TRANSPORT object. It is not a published result, it has no draw_id and
 * it cannot be settled against: publishing happens only through the existing
 * draw result confirmation boundary, which re-reads the digits from the staged
 * import row rather than trusting this object across a process boundary.
 *
 * `digits` stays a string so '0119' survives the trip; `source` records which
 * provider produced it, because a fixture result must never be mistaken for an
 * official one.
 */
final readonly class DigitDrawResult
{
    public function __construct(
        public string $gameCode,
        public string $drawNo,
        public string $digits,
        public string $drawnAt,
        public string $source,
    ) {}

    /**
     * True when every character is a digit and the length is what the game
     * expects. Length is checked by the caller against the game definition, so
     * this method only answers "is this a digit string at all".
     */
    public function isDigitString(): bool
    {
        return $this->digits !== '' && ctype_digit($this->digits);
    }

    /**
     * @return array{game_code: string, draw_no: string, digits: string, drawn_at: string, source: string}
     */
    public function toArray(): array
    {
        return [
            'game_code' => $this->gameCode,
            'draw_no' => $this->drawNo,
            'digits' => $this->digits,
            'drawn_at' => $this->drawnAt,
            'source' => $this->source,
        ];
    }
}
