<?php

declare(strict_types=1);

namespace App\DTOs\Account;

use App\Enums\AccountGradeLevel;
use App\Enums\DiscountGame;

/**
 * The explicit answer to "does this grade discount this game?"
 * (PROMPT 2, section B).
 *
 * SILENCE IS NOT A STATE. Every (tier, game) pair has an answer and the
 * answer is one of exactly two states: ELIGIBLE with the tier's rate,
 * or NOT_ELIGIBLE with an explicit zero rate. A page, an API row or a
 * snapshot that omits the game entirely would leave the reader to
 * guess; this object makes omission impossible.
 *
 * The rate is a decimal STRING fraction of one unit ('0.0600'), the
 * same shape the engine applies. The not-entitled case carries
 * '0.0000' - a stated zero, never a null that could be mistaken for
 * "unknown".
 */
final readonly class GradeDiscountEntitlement
{
    public const STATE_ELIGIBLE = 'ELIGIBLE';

    public const STATE_NOT_ELIGIBLE = 'NOT_ELIGIBLE';

    public function __construct(
        public DiscountGame $game,
        public AccountGradeLevel $level,
        public bool $eligible,
        public string $rate,
        public string $state,
        public string $ruleVersion,
    ) {}

    /**
     * An entitled (tier, game) pair: the tier's own rate applies.
     */
    public static function eligible(DiscountGame $game, GradeTier $tier): self
    {
        return new self(
            game: $game,
            level: $tier->level,
            eligible: true,
            rate: $tier->discountRate,
            state: self::STATE_ELIGIBLE,
            ruleVersion: $tier->ruleVersion,
        );
    }

    /**
     * A non-entitled (tier, game) pair: exactly zero, stated explicitly.
     */
    public static function notEligible(DiscountGame $game, GradeTier $tier): self
    {
        return new self(
            game: $game,
            level: $tier->level,
            eligible: false,
            rate: '0.0000',
            state: self::STATE_NOT_ELIGIBLE,
            ruleVersion: $tier->ruleVersion,
        );
    }

    public function isEligible(): bool
    {
        return $this->eligible;
    }

    /**
     * The display percentage ('0.0600' -> '6.00'), derived from the
     * same fraction the engine applies.
     */
    public function ratePercent(): string
    {
        return bcadd(bcmul($this->rate, '100', 2), '0', 2);
    }

    /**
     * Safe projection for API rows and snapshots.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'game' => $this->game->value,
            'grade' => $this->level->value,
            'eligible' => $this->eligible,
            'rate' => $this->rate,
            'rate_percent' => $this->ratePercent(),
            'state' => $this->state,
            'rule_version' => $this->ruleVersion,
        ];
    }
}
