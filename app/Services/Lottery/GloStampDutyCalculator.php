<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use InvalidArgumentException;

/**
 * Official GLO stamp-duty calculator (preserved verified rule).
 *
 *   stamp_duty = ceil(gross_prize / 200) × 1 THB
 *   income tax = exempt
 *
 * EXACT BAHT ONLY
 * Gross arrives as a decimal string. Division uses BCMath at scale 0 for the
 * ceiling of (gross / 200): any fractional baht rounds UP to the next whole
 * baht duty unit, never down, never via float. The result is always a
 * 2-decimal string ("30000.00") so callers stay in the string-money domain.
 *
 * DO NOT replace with 0.5% float withholding or 1% income tax — both are
 * regressions against the verified GLO treatment.
 */
class GloStampDutyCalculator
{
    public const DIVISOR = '200';

    public const UNIT_BAHT = '1.00';

    /**
     * Stamp duty for a gross prize, as a 2-decimal THB string.
     *
     * @throws InvalidArgumentException when gross is not a non-negative decimal string
     */
    public function dutyFor(string $grossPrize): string
    {
        $gross = $this->assertMoney($grossPrize);

        if ($gross === '0.00') {
            return '0.00';
        }

        $divisor = (string) config('glo.stamp_duty.divisor', self::DIVISOR);

        if (! preg_match('/^[1-9][0-9]*$/', $divisor)) {
            throw new InvalidArgumentException('glo.stamp_duty.divisor must be a positive integer string.');
        }

        // ceil(gross / divisor) in whole duty units, BCMath only:
        // quotient at scale 0 truncates; if quotient*divisor < gross there is
        // a fractional part and we must add one full unit (each unit is 1 THB).
        // NOTE: bcmod() defaults to scale 0 and would erase the fraction on
        // decimal gross values — never use it here without a product compare.
        $quotient = bcdiv($gross, $divisor, 0);
        $product = bcmul($quotient, $divisor, 2);

        if (bccomp($product, $gross, 2) < 0) {
            $quotient = bcadd($quotient, '1', 0);
        }

        return bcadd($quotient, '0.00', 2);
    }

    /**
     * Net prize after stamp duty (income tax exempt — no further deduction).
     *
     * @throws InvalidArgumentException
     */
    public function netAfterDuty(string $grossPrize): string
    {
        $gross = $this->assertMoney($grossPrize);
        $duty = $this->dutyFor($gross);

        $net = bcsub($gross, $duty, 2);

        if (bccomp($net, '0.00', 2) < 0) {
            $net = '0.00';
        }

        return $net;
    }

    /**
     * Full breakdown for claim records and admin display.
     *
     * @return array{gross_prize: string, stamp_duty: string, net_prize: string, income_tax: string, rule: string}
     *
     * @throws InvalidArgumentException
     */
    public function breakdown(string $grossPrize): array
    {
        $gross = $this->assertMoney($grossPrize);
        $duty = $this->dutyFor($gross);
        $net = $this->netAfterDuty($gross);

        return [
            'gross_prize' => $gross,
            'stamp_duty' => $duty,
            'net_prize' => $net,
            'income_tax' => '0.00',
            'rule' => 'ceil(gross/'.self::DIVISOR.') × 1 THB; income tax exempt',
        ];
    }

    private function assertMoney(string $value): string
    {
        if (! is_string($value) || ! preg_match('/^-?\d+(\.\d{1,2})?$/', trim($value))) {
            throw new InvalidArgumentException('Gross prize must be a decimal string with at most 2 fraction digits.');
        }

        $trimmed = trim($value);

        if (bccomp($trimmed, '0.00', 2) < 0) {
            throw new InvalidArgumentException('Gross prize must not be negative.');
        }

        return bcadd($trimmed, '0.00', 2);
    }
}
