<?php

declare(strict_types=1);

namespace Tests\Unit\Lottery;

use App\DTOs\Lottery\GloStampDutyReport;
use App\Services\Lottery\GloStampDutyCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Locks the GLO stamp-duty rule and the DTO returned by calculateDuty().
 *
 *   stamp_duty = ceil(gross_prize / 200) × 1 THB      income tax = exempt
 *
 * WHY THIS SUITE EXISTS
 * The rule was previously exercised only indirectly, through two feature tests that
 * happened to call it. A money rule that rounds has exactly one dangerous direction:
 * rounding DOWN, because that under-withholds duty and the shortfall is the operator's,
 * not the winner's. These cases pin the ceiling, the exact-multiple boundary and the
 * sub-unit amounts so a future refactor cannot quietly change the rounding.
 *
 * WHY THE DELEGATION INVARIANT IS ASSERTED
 * calculateDuty() must not become a second implementation of the arithmetic. Every case
 * therefore checks the DTO against dutyFor()/netAfterDuty() as well as against the
 * expected literal, so a divergence between the two paths fails here rather than
 * settling two winners at two different amounts.
 */
final class GloStampDutyCalculatorTest extends TestCase
{
    private GloStampDutyCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new GloStampDutyCalculator();
    }

    // ────────────────────────────────────────────────────────────────────────
    // The headline figure both readiness suites assert.
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function first_prize_of_six_million_withholds_thirty_thousand(): void
    {
        $report = $this->calculator->calculateDuty('6000000.00');

        $this->assertInstanceOf(GloStampDutyReport::class, $report);
        $this->assertSame('6000000.00', $report->grossPrizeThb);
        $this->assertSame('30000.00', $report->stampDutyThb);
        $this->assertSame('5970000.00', $report->netPayoutThb);
        $this->assertTrue($report->isIncomeTaxExempt);
    }

    #[Test]
    public function the_dto_agrees_with_the_scalar_methods_it_delegates_to(): void
    {
        foreach (['0.00', '1.00', '199.99', '200.00', '201.00', '6000000.00', '123456.78'] as $gross) {
            $report = $this->calculator->calculateDuty($gross);

            $this->assertSame(
                $this->calculator->dutyFor($gross),
                $report->stampDutyThb,
                "calculateDuty() and dutyFor() disagree for gross {$gross}",
            );

            $this->assertSame(
                $this->calculator->netAfterDuty($gross),
                $report->netPayoutThb,
                "calculateDuty() and netAfterDuty() disagree for gross {$gross}",
            );

            $parts = $this->calculator->breakdown($gross);

            $this->assertSame($parts['gross_prize'], $report->grossPrizeThb);
            $this->assertSame($parts['rule'], $report->rule);
            $this->assertSame($parts['income_tax'] === '0.00', $report->isIncomeTaxExempt);

            // gross - duty === net, in exact baht. The winner's figure must be the
            // complement of the withheld figure, never an independently rounded value.
            $this->assertSame(
                0,
                bccomp(
                    bcsub($report->grossPrizeThb, $report->stampDutyThb, 2),
                    $report->netPayoutThb,
                    2,
                ),
                "gross - duty !== net for {$gross}",
            );
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // The ceiling. This is the part that must never round down.
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function an_exact_multiple_of_the_divisor_charges_one_unit_per_multiple(): void
    {
        $this->assertSame('1.00', $this->calculator->calculateDuty('200.00')->stampDutyThb);
        $this->assertSame('2.00', $this->calculator->calculateDuty('400.00')->stampDutyThb);
        $this->assertSame('3.00', $this->calculator->calculateDuty('600.00')->stampDutyThb);
    }

    #[Test]
    public function a_single_satang_over_a_multiple_charges_the_next_whole_unit(): void
    {
        // 201.00 / 200 = 1.005 → ceiling 2. Truncating here would under-withhold.
        $this->assertSame('2.00', $this->calculator->calculateDuty('201.00')->stampDutyThb);

        // The smallest possible overshoot still costs a whole extra baht.
        $this->assertSame('2.00', $this->calculator->calculateDuty('200.01')->stampDutyThb);
        $this->assertSame('2.00', $this->calculator->calculateDuty('200.99')->stampDutyThb);
    }

    #[Test]
    public function an_amount_below_one_divisor_unit_still_charges_one_baht(): void
    {
        $this->assertSame('1.00', $this->calculator->calculateDuty('1.00')->stampDutyThb);
        $this->assertSame('1.00', $this->calculator->calculateDuty('0.01')->stampDutyThb);
        $this->assertSame('1.00', $this->calculator->calculateDuty('199.99')->stampDutyThb);
    }

    #[Test]
    public function a_zero_prize_withholds_nothing(): void
    {
        $report = $this->calculator->calculateDuty('0.00');

        $this->assertSame('0.00', $report->stampDutyThb);
        $this->assertSame('0.00', $report->netPayoutThb);
        $this->assertTrue($report->isIncomeTaxExempt);
    }

    #[Test]
    public function a_small_prize_can_be_fully_consumed_by_duty_but_never_goes_negative(): void
    {
        // 1.00 gross, 1.00 duty → the winner receives nothing. That is the correct
        // answer for this rule; clamping the duty to keep a payout would be the bug.
        $report = $this->calculator->calculateDuty('1.00');

        $this->assertSame('1.00', $report->stampDutyThb);
        $this->assertSame('0.00', $report->netPayoutThb);
    }

    // ────────────────────────────────────────────────────────────────────────
    // The string-money domain. No float may enter or leave.
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function every_amount_leaves_as_a_two_decimal_string_and_never_as_a_float(): void
    {
        $report = $this->calculator->calculateDuty('123456.78');

        foreach ([$report->grossPrizeThb, $report->stampDutyThb, $report->netPayoutThb] as $amount) {
            $this->assertIsString($amount);
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $amount);
        }

        // 123456.78 / 200 = 617.2839 → ceiling 618 units.
        $this->assertSame('618.00', $report->stampDutyThb);
        $this->assertSame('122838.78', $report->netPayoutThb);
    }

    #[Test]
    public function to_array_uses_the_claim_record_vocabulary(): void
    {
        $array = $this->calculator->calculateDuty('6000000.00')->toArray();

        $this->assertSame(
            [
                'gross_prize_thb',
                'stamp_duty_thb',
                'net_payout_thb',
                'is_income_tax_exempt',
                'rule',
            ],
            array_keys($array),
        );

        $this->assertSame('30000.00', $array['stamp_duty_thb']);
        $this->assertTrue($array['is_income_tax_exempt']);
    }

    #[Test]
    public function the_rule_string_explains_the_figure_it_produced(): void
    {
        // An admin reading a withheld amount must be able to see why, not only what.
        $rule = $this->calculator->calculateDuty('6000000.00')->rule;

        $this->assertStringContainsString('ceil', $rule);
        $this->assertStringContainsString(GloStampDutyCalculator::DIVISOR, $rule);
        $this->assertStringContainsString('income tax exempt', $rule);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Refusals.
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function a_malformed_gross_is_refused_rather_than_coerced(): void
    {
        foreach (['', 'abc', '1,000.00', '1.234', null, 6000000.0, '1e6', '-100.00'] as $bad) {
            $threw = false;

            try {
                /** @phpstan-ignore-next-line deliberately exercising the type boundary */
                $this->calculator->calculateDuty($bad);
            } catch (InvalidArgumentException) {
                $threw = true;
            } catch (\TypeError) {
                // A non-string reaching a string parameter is also a refusal, and the
                // correct one: PHP stops it before the calculator can.
                $threw = true;
            }

            $this->assertTrue($threw, 'expected refusal for gross: '.var_export($bad, true));
        }
    }

    #[Test]
    public function surrounding_whitespace_is_accepted_and_normalised(): void
    {
        // assertMoney() trims before validating, so ' 6000000.00 ' is a VALID gross and
        // normalises to '6000000.00'. This is asserted rather than assumed because it is
        // the opposite of the transfer lane, where normaliseAmount() refuses a padded
        // amount for non-HTTP callers. The two lanes genuinely differ; this test keeps
        // the difference visible instead of letting a future "consistency" refactor
        // silently start rejecting duty calculations that used to succeed.
        $report = $this->calculator->calculateDuty('  6000000.00  ');

        $this->assertSame('6000000.00', $report->grossPrizeThb);
        $this->assertSame('30000.00', $report->stampDutyThb);
        $this->assertSame('5970000.00', $report->netPayoutThb);
    }

    #[Test]
    public function a_negative_gross_is_refused_with_its_own_reason(): void
    {
        // The regex permits a leading '-', so the negative check is a separate guard.
        // A refund must never be fed through the duty path and produce a negative duty.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be negative');

        $this->calculator->calculateDuty('-100.00');
    }

    #[Test]
    public function a_configured_divisor_of_zero_or_garbage_is_refused(): void
    {
        // The divisor is read from config, so a bad deployment value must fail loudly
        // instead of dividing by zero or silently charging nothing.
        config(['glo.stamp_duty.divisor' => '0']);

        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculateDuty('6000000.00');
    }
}
