<?php

declare(strict_types=1);

namespace App\DTOs\Lottery;

use App\Enums\DiscountGame;
use App\Enums\DiscountLottery;

/**
 * ONE immutable row of the canonical game/prize/discount matrix
 * (PROMPT 2, section F).
 *
 * A DiscountRule is built exclusively by CanonicalDiscountMatrixService
 * from config/lotto_discount_matrix.php - never constructed ad hoc -
 * so a published multiplier or percentage can never disagree with the
 * configuration the calculators read.
 *
 * CONTRACT
 * - mode keys: 'D' (direct), 'R' (reverse) for the seven headline
 *   games; '*' (single) for the single-multiplier variants. Headline
 *   games MUST publish both D and R; variants MUST publish exactly one
 *   '*' multiplier. Anything else is a load-time validation error;
 * - discount is a whole-percent decimal STRING ('35.00') or NULL.
 *   NULL means NOT_CONFIGURED - an unspecified percentage is a STATE,
 *   never a guessed zero;
 * - base_stake is the per-unit stake the win multipliers are quoted
 *   against ('1.00' THB), a published figure, never a computed one.
 */
final readonly class DiscountRule
{
    public const MODE_DIRECT = 'D';

    public const MODE_REVERSE = 'R';

    public const MODE_SINGLE = '*';

    public const STATE_CONFIGURED = 'CONFIGURED';

    public const STATE_NOT_CONFIGURED = 'NOT_CONFIGURED';

    /**
     * @param  array<string, string>  $modes  mode key => win multiplier
     */
    public function __construct(
        public DiscountLottery $lottery,
        public DiscountGame $game,
        public array $modes,
        public ?string $discount,
        public string $baseStake,
        public string $currency,
        public string $ruleVersion,
        public bool $enabled,
        public bool $publicVisible,
        public ?string $effectiveFrom,
        public ?string $effectiveTo,
    ) {}

    /**
     * Does this rule publish the given mode key?
     */
    public function hasMode(string $mode): bool
    {
        return array_key_exists($mode, $this->modes);
    }

    /**
     * The win multiplier for a mode key. Refuses an unpublished mode:
     * quoting a game in a direction it does not trade in is a pricing
     * error, not a fallback-to-something case.
     *
     * @throws \InvalidArgumentException
     */
    public function multiplier(string $mode): string
    {
        if (! array_key_exists($mode, $this->modes)) {
            throw new \InvalidArgumentException(sprintf(
                'Game %s does not publish mode %s.',
                $this->game->value,
                $mode,
            ));
        }

        return $this->modes[$mode];
    }

    /**
     * Is a game discount percentage published for this rule?
     */
    public function isDiscountConfigured(): bool
    {
        return $this->discount !== null;
    }

    /**
     * The published state of the game discount percentage.
     */
    public function discountState(): string
    {
        return $this->discount !== null ? self::STATE_CONFIGURED : self::STATE_NOT_CONFIGURED;
    }

    /**
     * Display form: '35.00%' when configured, the literal
     * NOT_CONFIGURED state otherwise - never a fabricated zero.
     */
    public function discountDisplay(): string
    {
        return $this->discount !== null
            ? bcadd($this->discount, '0', 2).'%'
            : self::STATE_NOT_CONFIGURED;
    }

    /**
     * Safe projection for logs/metadata.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lottery' => $this->lottery->value,
            'game' => $this->game->value,
            'modes' => $this->modes,
            'discount' => $this->discount,
            'discount_state' => $this->discountState(),
            'base_stake' => $this->baseStake,
            'currency' => $this->currency,
            'rule_version' => $this->ruleVersion,
            'enabled' => $this->enabled,
            'public_visible' => $this->publicVisible,
            'effective_from' => $this->effectiveFrom,
            'effective_to' => $this->effectiveTo,
        ];
    }
}
