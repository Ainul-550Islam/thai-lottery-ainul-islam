<?php

declare(strict_types=1);

namespace Tests\Unit\Wallet\Conversion;

use App\ValueObjects\ConversionRate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rate value object: one place where a fraction becomes money.
 *
 * WHY THIS IS UNIT-TESTED AND NOT LEFT TO THE FEATURE TESTS
 * `ConversionRate` decides how many decimal places a fee carries and which way a
 * half-satang rounds. Both choices are invisible in a happy-path test at 1% on
 * 100.00 — they decide what happens at 1% on 0.05, or at a rate of 0.00005,
 * where a half-satang outcome becomes real money for a real member. A rounding
 * rule that is only exercised by accident is a rounding rule nobody owns.
 *
 * The cases below pin the two decisions that matter: money is rounded HALF UP
 * (a member is never short-changed in the platform's favour by a truncation) and
 * the rate is exact, held as a decimal string at a chosen scale so no float
 * touches the calculation at any point.
 */
class ConversionRateTest extends TestCase
{
    #[Test]
    public function a_rate_from_the_published_row_is_exact(): void
    {
        $rate = ConversionRate::fromString('0.0100', 4);

        $this->assertSame('0.0100', $rate->toString());
        $this->assertSame('1.00', $rate->percentage());
        $this->assertSame(100, $rate->basisPoints());
        $this->assertFalse($rate->isZero());
    }

    #[Test]
    public function the_api_percentage_is_what_the_page_prints(): void
    {
        // The value test: whatever a rate is, its percentage must be the number
        // a member reads on the fees page. If these ever diverge, the published
        // tariff and the member's expectation diverge with them.
        $this->assertSame('1.00', ConversionRate::fromString('0.01', 4)->percentage());
        $this->assertSame('1.50', ConversionRate::fromString('0.015', 4)->percentage());
        $this->assertSame('0.25', ConversionRate::fromString('0.0025', 4)->percentage());
        $this->assertSame('0.00', ConversionRate::fromString('0.0000001', 8)->percentage());
    }

    #[Test]
    public function a_fee_is_rounded_half_up_at_the_boundary_and_never_truncated(): void
    {
        // The boundary case, chosen so the rule is visible: 5% of 0.10 is exactly
        // 0.005, whose first dropped digit is a 5. Truncation gives 0.00, half-up
        // gives 0.01. The platform is not keeping that satang by accident; the
        // rule says the member pays the rounded fee.
        $this->assertSame('0.01', ConversionRate::fromString('0.05', 4)->feeOn('0.10'));
        $this->assertSame('0.09', ConversionRate::fromString('0.05', 4)->netOf('0.10'));

        // Just below the boundary the same rate keeps nothing: 2% of 0.20 is
        // 0.004, and rounding it up to a satang would charge a fee the tariff
        // does not describe. Half-up rounds at 5 and above, not at every non-zero
        // remainder.
        $this->assertSame('0.00', ConversionRate::fromString('0.02', 4)->feeOn('0.20'));

        // And one step above: 1.5% of 0.50 is 0.0075 — first dropped digit 7.
        $this->assertSame('0.01', ConversionRate::fromString('0.015', 4)->feeOn('0.50'));
        $this->assertSame('0.49', ConversionRate::fromString('0.015', 4)->netOf('0.50'));

        // The published 1% tariff on the amounts members actually convert.
        $this->assertSame('1.00', ConversionRate::fromString('0.01', 4)->feeOn('100.00'));
        $this->assertSame('99.00', ConversionRate::fromString('0.01', 4)->netOf('100.00'));
    }

    #[Test]
    public function fee_and_net_always_add_up_to_the_original_amount(): void
    {
        $rate = ConversionRate::fromString('0.0125', 4);

        foreach (['0.01', '0.05', '1.00', '9.99', '100.00', '12345.67'] as $amount) {
            $fee = $rate->feeOn($amount);
            $net = $rate->netOf($amount);

            $this->assertSame(
                0,
                bccomp($amount, bcadd($fee, $net, 2), 2),
                sprintf('Fee %s plus net %s must equal the amount %s exactly.', $fee, $net, $amount),
            );
        }
    }

    #[Test]
    public function basis_points_round_trip_through_the_rate(): void
    {
        $rate = ConversionRate::fromBasisPoints(100, 4);

        $this->assertSame('0.0100', $rate->toString());
        $this->assertSame('1.00', $rate->percentage());

        $this->assertSame('0.0250', ConversionRate::fromBasisPoints(250, 4)->toString());
        $this->assertTrue(ConversionRate::fromBasisPoints(0)->isZero());
    }

    #[Test]
    public function a_rate_may_not_be_negative(): void
    {
        // A negative fee would pay the member to convert: a money-printing loop.
        $this->expectException(InvalidArgumentException::class);

        ConversionRate::fromString('-0.01', 4);
    }

    #[Test]
    public function a_rate_may_not_be_read_at_an_absurd_scale(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConversionRate::fromString('0.01', 12);
    }

    #[Test]
    public function a_blank_rate_is_zero_rather_than_an_exception(): void
    {
        // A blank published rate means "no price", and the caller reports that as
        // an unconfigured rule instead of a crash. The distinction between zero
        // and "unknown" is made by the caller, not lost here.
        $this->assertTrue(ConversionRate::fromString(null, 4)->isZero());
        $this->assertTrue(ConversionRate::fromString('', 4)->isZero());
    }

    #[Test]
    public function rates_compare_by_value_not_by_identity(): void
    {
        $this->assertTrue(
            ConversionRate::fromString('0.0100', 4)->equals(ConversionRate::fromString('0.01', 4)),
        );
        $this->assertFalse(
            ConversionRate::fromString('0.0100', 4)->equals(ConversionRate::fromString('0.0200', 4)),
        );
    }
}
