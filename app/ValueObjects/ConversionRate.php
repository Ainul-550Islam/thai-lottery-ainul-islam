<?php

declare(strict_types=1);

namespace App\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An exact, immutable conversion fee rate, expressed as a decimal fraction.
 *
 * WHY NOT A FLOAT
 * The rate is the number every baht of the conversion is multiplied by. A
 * binary float cannot represent 0.01 or 0.0100 exactly, so a float rate turns a
 * 1% fee on 1,234.56 THB into 12.345600000000001 and the rounding of that value
 * becomes a coin-flip decided by the runtime. This class therefore stores the
 * rate as a normalised decimal STRING and performs every multiplication with
 * bcmath, the same discipline App\Services\Finance\Money applies to balances.
 *
 * WHY THE ROUNDING MODE IS EXPLICIT
 * Money is rounded half-up, once, at the boundary where the fee becomes an
 * amount — never carried as a fractional satang through a subtraction and never
 * rounded twice. roundHalfUp() adds half of one unit at the target scale and
 * then truncates, which is exact for non-negative decimals: 10.005 -> 10.01 and
 * 10.004 -> 10.00. Negative inputs are rejected outright, because a negative
 * fee has no meaning in this domain and must never reach a ledger.
 *
 * The configured scale (config/conversions.php, `fee.scale`) is passed in by
 * the calculator so the rate itself stays currency-agnostic.
 */
final class ConversionRate implements JsonSerializable, Stringable
{
    public const DEFAULT_SCALE = 4;

    private function __construct(
        private readonly string $rate,
    ) {}

    /**
     * Build a rate from a decimal string, int or float.
     *
     * Floats are accepted ONLY for developer convenience in tests and are
     * converted through number_format at the target scale before bcmath sees
     * them, so the float never survives into arithmetic.
     */
    public static function fromString(string|int|float|null $rate, int $scale = self::DEFAULT_SCALE): self
    {
        if ($scale < 0 || $scale > 8) {
            throw new InvalidArgumentException('A conversion rate scale must be between 0 and 8.');
        }

        if ($rate === null) {
            $rate = 0;
        }

        $normalised = is_float($rate)
            ? number_format($rate, $scale, '.', '')
            : (string) $rate;

        $normalised = trim($normalised);

        if ($normalised === '') {
            $normalised = '0';
        }

        if (preg_match('/^\d+(\.\d+)?$/', $normalised) !== 1) {
            throw new InvalidArgumentException(
                sprintf('A conversion rate must be a non-negative decimal value; [%s] was given.', $normalised),
            );
        }

        return new self(self::normalise($normalised, $scale));
    }

    /**
     * Build a rate from whole basis points (1 basis point = 0.01%): 100 bps = 1%.
     */
    public static function fromBasisPoints(int $basisPoints, int $scale = self::DEFAULT_SCALE): self
    {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException('A conversion rate cannot be expressed in negative basis points.');
        }

        $rate = bcdiv((string) $basisPoints, '10000', $scale);

        return new self(self::normalise($rate, $scale));
    }

    public static function zero(int $scale = self::DEFAULT_SCALE): self
    {
        return new self(self::normalise('0', $scale));
    }

    /**
     * The normalised decimal string, e.g. "0.0100".
     */
    public function toString(): string
    {
        return $this->rate;
    }

    /**
     * The rate as percentage points, e.g. "1.00" for a 1.00% fee.
     */
    public function percentage(int $decimals = 2): string
    {
        return self::roundHalfUp(bcmul($this->rate, '100', $decimals + 2), $decimals);
    }

    /**
     * The rate in whole basis points, truncated toward zero. 0.0100 -> 100.
     */
    public function basisPoints(): int
    {
        return (int) bcdiv(bcmul($this->rate, '10000', 0), '1', 0);
    }

    public function isZero(): bool
    {
        return bccomp($this->rate, '0', self::DEFAULT_SCALE) === 0;
    }

    public function equals(self $other): bool
    {
        return bccomp($this->rate, $other->rate, self::DEFAULT_SCALE) === 0;
    }

    /**
     * The fee this rate charges on an amount, rounded half-up once.
     *
     * @param  string  $amount  an exact non-negative decimal string, e.g. "1000.00"
     */
    public function feeOn(string $amount, int $scale = 2): string
    {
        self::assertNonNegativeAmount($amount);

        return self::roundHalfUp(bcmul($amount, $this->rate, $scale + 2), $scale);
    }

    /**
     * The amount left after this rate's fee is deducted.
     *
     * @param  string  $amount  an exact non-negative decimal string, e.g. "1000.00"
     */
    public function netOf(string $amount, int $scale = 2): string
    {
        self::assertNonNegativeAmount($amount);

        return self::roundHalfUp(
            bcsub($amount, $this->feeOn($amount, $scale), $scale + 2),
            $scale,
        );
    }

    /**
     * Gross the amount up so that, after this rate's fee, the net equals the
     * requested figure. Used when a member asks for "exactly 500 into Win".
     *
     * @param  string  $netAmount  the exact amount that must land in the destination
     */
    public function grossUp(string $netAmount, int $scale = 2): string
    {
        self::assertNonNegativeAmount($netAmount);

        $rate = (float) $this->rate;

        if ($rate <= 0.0) {
            return self::roundHalfUp($netAmount, $scale);
        }

        $gross = bcdiv($netAmount, bcsub('1', $this->rate, 8), $scale + 2);

        return self::roundHalfUp($gross, $scale);
    }

    /**
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return [
            'rate' => $this->rate,
            'percentage' => $this->percentage(),
            'basis_points' => $this->basisPoints(),
        ];
    }

    public function jsonSerialize(): string
    {
        return $this->rate;
    }

    public function __toString(): string
    {
        return $this->rate;
    }

    /**
     * Pad or trim a decimal string to an exact number of fractional digits,
     * without rounding (the caller passes a value already computed at a finer
     * scale when rounding matters).
     */
    private static function normalise(string $value, int $scale): string
    {
        if ($scale === 0) {
            return bcdiv($value, '1', 0);
        }

        return bcadd($value, '0', $scale);
    }

    /**
     * Round half-up to $scale decimal places using string arithmetic only.
     *
     * Adding half of one unit at the target scale and then truncating is exact
     * for non-negative inputs: scale 2 turns 10.004 into "10.00" and 10.005 into
     * "10.01", with no float ever involved.
     */
    private static function roundHalfUp(string $value, int $scale): string
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('A rounding scale cannot be negative.');
        }

        $half = '0.'.str_repeat('0', $scale).'5';

        if ($scale === 0) {
            $half = '0.5';
        }

        return bcadd($value, $half, $scale);
    }

    private static function assertNonNegativeAmount(string $amount): void
    {
        if (preg_match('/^\d+(\.\d+)?$/', trim($amount)) !== 1) {
            throw new InvalidArgumentException(
                sprintf('An amount must be a non-negative decimal string; [%s] was given.', $amount),
            );
        }
    }
}
