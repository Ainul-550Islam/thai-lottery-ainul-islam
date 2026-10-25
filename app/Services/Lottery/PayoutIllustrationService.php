<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\BetType;
use App\Services\Betting\PayoutMultiplierService;
use App\ValueObjects\PayoutMultiplier;
use InvalidArgumentException;

/**
 * Deterministic payout ILLUSTRATION — stake × published rate.
 *
 * =========================================================================
 * WHAT THIS IS, AND WHAT IT IS NOT
 * =========================================================================
 * It is arithmetic: "if this selection matched, the published rate would
 * return this much". It is NOT a simulator, NOT a prediction, NOT a
 * probability, and NOT a promise of winnings:
 *   - no random number is drawn, generated or consulted anywhere;
 *   - no probability, odds, "chance" or win-rate figure is computed or
 *     returned — those cannot be derived from a payout rate and this service
 *     refuses to invent them;
 *   - the same (bet type, stake) always produces the same answer, byte for
 *     byte, in the same environment.
 *
 * =========================================================================
 * WHERE THE RATE COMES FROM
 * =========================================================================
 * Never from this class and never from a client. The rate is resolved by
 * App\Services\Betting\PayoutMultiplierService from the source the project
 * declares in config('lottery.payouts.multiplier_source'). That service is
 * strict: where the declared sources contradict one another (Run is sold at
 * run_top = 3 and run_bottom = 4 while lottery.types.run and the BetType enum
 * both say 12) it refuses to price the market rather than choosing a value.
 *
 * A bet type with no single published rate therefore answers NOT_CONFIGURED —
 * for THAT bet type only, with HTTP 200 — instead of illustrating a number
 * nobody signed off. That is the honest answer, and it is the whole point of
 * the lane.
 *
 * =========================================================================
 * EXACT ARITHMETIC
 * =========================================================================
 * bcmath only, at a fixed scale, with ONE half-up rounding step at the end —
 * the same scale-2 half-up policy the finance engine applies to money. There
 * is no binary floating point conversion and no native rounding call in this
 * class: a payout figure that disagrees with the ledger because of a binary
 * rounding artefact is a support ticket nobody can explain.
 */
final class PayoutIllustrationService
{
    /** A rate exists and the figures below are real arithmetic on it. */
    public const STATE_READY = 'READY';

    /** No single published rate exists for this bet type right now. */
    public const STATE_NOT_CONFIGURED = 'NOT_CONFIGURED';

    /** Monetary scale of every illustrated figure. */
    private const AMOUNT_SCALE = 2;

    /** Intermediate scale used before the single rounding step. */
    private const INTERMEDIATE_SCALE = 8;

    public function __construct(
        private readonly PayoutMultiplierService $multipliers,
    ) {
    }

    /**
     * Illustrate one stake against one bet type's published rate.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the bet type is unsupported or the
     *                                  stake is malformed, non-positive or out of bounds.
     *                                  The message is a translation key
     *                                  (payout_illustration.*), never a sentence
     *                                  assembled in code.
     */
    public function illustrate(string $betType, string $stake, string $locale = 'en'): array
    {
        $locale = $this->locale($locale);
        $type = $this->betType($betType);
        $canonicalStake = $this->assertStake($stake);

        $markets = $this->marketsFor($type);
        $resolution = $this->resolveRate($markets);

        $currency = (string) config('payout_illustration.currency', config('lottery.betting.currency', 'THB'));
        $disclaimerKey = (string) config('payout_illustration.disclaimer_key', 'payout_illustration.disclaimer');

        $base = [
            'bet_type' => $type->value,
            'stake' => $canonicalStake,
            'currency' => $currency,
            'disclaimer_key' => $disclaimerKey,
            'disclaimer' => (string) trans($disclaimerKey, [], $locale),
        ];

        if ($resolution['multiplier'] === null) {
            $reasonKey = (string) config('payout_illustration.not_configured_key', 'payout_illustration.not_configured_multiplier');

            return $base + [
                'state' => self::STATE_NOT_CONFIGURED,
                'multiplier' => null,
                'gross_return_if_matched' => null,
                'net_if_matched' => null,
                'reason_key' => $reasonKey,
                'reason' => (string) trans($reasonKey, [], $locale),
                'basis' => [
                    'multiplier_source' => $this->multipliers->declaredSource(),
                    'markets' => $resolution['markets'],
                    'rounding' => $this->roundingPolicy(),
                    'deductions' => 'none_published',
                ],
            ];
        }

        $multiplier = $resolution['multiplier'];
        $scale = $this->roundingScale();

        $gross = self::roundHalfUp(
            bcmul($canonicalStake, $multiplier->value(), self::INTERMEDIATE_SCALE),
            $scale,
        );

        // No published deduction applies to a platform-market illustration:
        // stamp duty and prize withholding belong to GLO-compatible prize
        // claims. Net therefore equals gross, and the basis says so instead of
        // leaving a reader to guess what "net" removed.
        $net = $gross;

        return $base + [
            'state' => self::STATE_READY,
            'multiplier' => $multiplier->value(),
            'gross_return_if_matched' => $gross,
            'net_if_matched' => $net,
            'basis' => [
                'multiplier_source' => $this->multipliers->declaredSource(),
                'markets' => $resolution['markets'],
                'rounding' => $this->roundingPolicy(),
                'deductions' => 'none_published',
                'net_basis_key' => (string) config('payout_illustration.net_basis_key', 'payout_illustration.net_basis_no_deduction'),
                'net_basis' => (string) trans(
                    (string) config('payout_illustration.net_basis_key', 'payout_illustration.net_basis_no_deduction'),
                    [],
                    $locale,
                ),
            ],
        ];
    }

    /**
     * Exact half-up rounding for a decimal string, using bcmath only.
     *
     * Implemented by adding half of the last kept unit and truncating. The
     * native rounding helper is deliberately not used: it operates on binary
     * floats and turns 2.675 into 2.67. Negative values round away from zero
     * (half-up in magnitude), the documented policy of the finance engine.
     *
     * @throws InvalidArgumentException when the value is not a decimal string
     */
    public static function roundHalfUp(string $value, int $scale = self::AMOUNT_SCALE): string
    {
        $value = trim($value);
        $scale = max(0, min(12, $scale));

        if (preg_match('/^[+-]?\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException('Amount must be a decimal string, got: '.mb_substr($value, 0, 40));
        }

        $negative = str_starts_with($value, '-');
        $absolute = ltrim($value, '+-');

        // Half of one unit at the kept scale: 0.5 at scale 0, 0.05 at scale 1,
        // 0.005 at scale 2 — i.e. a 5 one place beyond the last kept decimal.
        $half = $scale === 0
            ? '0.5'
            : '0.'.str_repeat('0', $scale).'5';

        $rounded = bcadd($absolute, $half, $scale);

        return $negative && bccomp($rounded, '0', $scale) !== 0 ? '-'.$rounded : $rounded;
    }

    /**
     * The supported bet types, as configured.
     *
     * @return list<string>
     */
    public function supportedBetTypes(): array
    {
        $configured = array_values(array_filter(
            array_map('strval', (array) config('payout_illustration.supported_bet_types', [])),
            static fn (string $value): bool => $value !== '',
        ));

        if ($configured !== []) {
            return $configured;
        }

        return array_values(array_map(static fn (BetType $type): string => $type->value, BetType::cases()));
    }

    /**
     * Validate and canonicalise a stake, or refuse it.
     *
     * @throws InvalidArgumentException with a payout_illustration.* message key
     */
    public function assertStake(string $stake): string
    {
        $stake = trim($stake);
        $pattern = (string) config('payout_illustration.stake_pattern', '/^\d{1,13}(\.\d{1,2})?$/');

        if ($stake === '' || @preg_match($pattern, $stake) !== 1) {
            throw new InvalidArgumentException('payout_illustration.invalid_amount');
        }

        $amount = bcadd($stake, '0', self::AMOUNT_SCALE);

        if (bccomp($amount, '0', self::AMOUNT_SCALE) <= 0) {
            throw new InvalidArgumentException('payout_illustration.stake_not_positive');
        }

        $minimum = (string) config('payout_illustration.min_stake', '0');
        $maximum = (string) config('payout_illustration.max_stake', '0');

        if (is_numeric($maximum) && bccomp($maximum, '0', self::AMOUNT_SCALE) > 0
            && bccomp($amount, (string) $maximum, self::AMOUNT_SCALE) > 0) {
            throw new InvalidArgumentException('payout_illustration.stake_above_maximum');
        }

        if (is_numeric($minimum) && bccomp($minimum, '0', self::AMOUNT_SCALE) > 0
            && bccomp($amount, (string) $minimum, self::AMOUNT_SCALE) < 0) {
            throw new InvalidArgumentException('payout_illustration.stake_below_minimum');
        }

        return $amount;
    }

    private function betType(string $betType): BetType
    {
        $type = BetType::tryFrom(strtolower(trim($betType)));

        if (! $type instanceof BetType || ! in_array($type->value, $this->supportedBetTypes(), true)) {
            throw new InvalidArgumentException('payout_illustration.unsupported_bet_type');
        }

        return $type;
    }

    /**
     * The configured sellable markets of a bet type.
     *
     * Read from config('lottery.types.<type>.markets') — the same declaration
     * the bet engine sells from — and never assembled from what looks
     * plausible. A bet type with no configured market cannot be illustrated.
     *
     * @return list<string>
     */
    private function marketsFor(BetType $type): array
    {
        $markets = array_values(array_filter(
            array_map('strval', (array) config('lottery.types.'.$type->value.'.markets', [])),
            static fn (string $market): bool => $market !== '',
        ));

        if ($markets !== []) {
            return $markets;
        }

        foreach ((array) config('lottery.markets', []) as $key => $definition) {
            if (is_array($definition) && ($definition['bet_type'] ?? null) === $type->value) {
                $markets[] = (string) $key;
            }
        }

        return $markets;
    }

    /**
     * Resolve ONE published rate for the bet type.
     *
     * Returns null when any configured market cannot be priced (no usable
     * rate, a disabled market, or declared sources that contradict) or when
     * two of its markets disagree — a bet type whose own markets pay
     * differently has no single illustration to give.
     *
     * @param  list<string>  $markets
     * @return array{multiplier: PayoutMultiplier|null, markets: list<array<string, mixed>>}
     */
    private function resolveRate(array $markets): array
    {
        $detail = [];
        $resolved = [];
        $distinct = [];

        foreach ($markets as $market) {
            $multiplier = $this->multipliers->tryResolve($market);

            $detail[] = [
                'market' => $market,
                'multiplier' => $multiplier?->value(),
                'state' => $multiplier instanceof PayoutMultiplier ? self::STATE_READY : self::STATE_NOT_CONFIGURED,
            ];

            if (! $multiplier instanceof PayoutMultiplier) {
                continue;
            }

            $resolved[] = $multiplier;
            $canonical = $multiplier->value();

            if (! in_array($canonical, $distinct, true)) {
                $distinct[] = $canonical;
            }
        }

        if ($markets === [] || count($resolved) !== count($markets) || count($distinct) !== 1) {
            return ['multiplier' => null, 'markets' => $detail];
        }

        return ['multiplier' => $resolved[0], 'markets' => $detail];
    }

    /**
     * @return array{engine: string, scale: int, mode: string}
     */
    private function roundingPolicy(): array
    {
        return [
            'engine' => (string) config('payout_illustration.rounding.engine', 'bcmath'),
            'scale' => $this->roundingScale(),
            'mode' => (string) config('payout_illustration.rounding.mode', 'half_up'),
        ];
    }

    private function roundingScale(): int
    {
        return max(0, min(6, (int) config('payout_illustration.rounding.scale', self::AMOUNT_SCALE)));
    }

    private function locale(string $locale): string
    {
        $locale = strtolower(trim($locale));

        return in_array($locale, ['en', 'th', 'bn'], true) ? $locale : 'en';
    }
}
