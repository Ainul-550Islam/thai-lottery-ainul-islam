<?php

declare(strict_types=1);

namespace Tests\Unit\Wallet\Conversion;

use App\Enums\Currency;
use App\Enums\WalletConversionRejectionReason;
use App\ValueObjects\ConversionLimits;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The limits value object: every refusal a well-behaved member can hit.
 *
 * WHY THE BOUNDARIES ARE TESTED AT THE BOUNDARY
 * A limit is only meaningful at the edge. "The minimum is 10.00" tells nobody
 * anything until you know whether 10.00 passes and 9.99 fails, and whether a
 * daily ceiling of 1,000.00 permits a conversion that lands exactly on 1,000.00.
 * Off-by-one at a money ceiling is not a rounding note: it is either a refusal
 * of a legitimate conversion or an acceptance of one the platform said it would
 * decline.
 */
class ConversionLimitsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'min_amount' => '10.00',
            'max_amount' => '500000.00',
            'daily_limit' => '1000000.00',
            'monthly_limit' => '5000000.00',
            'enforce_daily' => true,
            'enforce_monthly' => true,
        ], $overrides);
    }

    #[Test]
    public function the_minimum_is_inclusive_and_one_satang_below_is_not(): void
    {
        $limits = ConversionLimits::fromConfig($this->config(), Currency::THB);

        $this->assertTrue($limits->allows('10.00'), 'The floor itself is a legal conversion.');
        $this->assertFalse($limits->allows('9.99'));
        $this->assertSame([], $limits->transactionViolations('10.00'));
    }

    #[Test]
    public function a_below_minimum_amount_reports_its_cause(): void
    {
        $limits = ConversionLimits::fromConfig($this->config(), Currency::THB);

        $this->assertSame(
            [WalletConversionRejectionReason::BelowMinimum],
            $limits->transactionViolations('9.99'),
            'The refusal must name the reason, so the member is told which number to change.',
        );
    }

    #[Test]
    public function the_ceiling_is_inclusive_and_one_satang_above_is_not(): void
    {
        $limits = ConversionLimits::fromConfig($this->config(), Currency::THB);

        $this->assertTrue($limits->allows('500000.00'));
        $this->assertFalse($limits->allows('500000.01'));
        $this->assertSame(
            [WalletConversionRejectionReason::AboveMaximum],
            $limits->transactionViolations('500000.01'),
        );
    }

    #[Test]
    public function a_daily_total_that_lands_exactly_on_the_ceiling_is_allowed(): void
    {
        $limits = ConversionLimits::fromConfig($this->config(['daily_limit' => '1000.00']), Currency::THB);

        // 900.00 already converted plus this 100.00 is exactly the ceiling. A
        // member who reaches their limit has not exceeded it, and refusing here
        // would make the published ceiling wrong by one conversion.
        $this->assertSame([], $limits->rollingViolations('100.00', '900.00'));
    }

    #[Test]
    public function a_daily_total_one_satang_over_the_ceiling_is_refused(): void
    {
        $limits = ConversionLimits::fromConfig($this->config(['daily_limit' => '1000.00']), Currency::THB);

        $this->assertSame(
            [WalletConversionRejectionReason::DailyLimitExceeded],
            $limits->rollingViolations('100.01', '900.00'),
        );
    }

    #[Test]
    public function the_monthly_ceiling_is_judged_separately_from_the_daily_one(): void
    {
        $limits = ConversionLimits::fromConfig(
            $this->config(['daily_limit' => '1000000.00', 'monthly_limit' => '500.00']),
            Currency::THB,
        );

        $this->assertSame(
            [WalletConversionRejectionReason::MonthlyLimitExceeded],
            $limits->rollingViolations('600.00', '0.00', '0.00'),
            'A small amount on a fresh day can still breach a month.',
        );
    }

    #[Test]
    public function a_disabled_ceiling_is_not_enforced_but_is_still_published(): void
    {
        $limits = ConversionLimits::fromConfig(
            $this->config(['daily_limit' => '1000.00', 'enforce_daily' => false]),
            Currency::THB,
        );

        $this->assertSame(
            [],
            $limits->rollingViolations('5000.00', '900.00'),
            'Turning enforcement off must actually stop refusing; a switch that only changes the copy is a trap.',
        );

        $this->assertSame('1000.00', (string) $limits->dailyLimit(), 'The number is still published so the UI can show it.');
        $this->assertTrue($limits->hasDailyLimit(), 'Published.');
        $this->assertFalse($limits->enforcesDailyLimit(), 'Not enforced.');

        $this->assertNull(
            $limits->remainingDaily('900.00'),
            'Head-room against a ceiling nobody enforces is a number that would mislead the member reading it.',
        );
    }

    #[Test]
    public function a_null_ceiling_means_no_ceiling_rather_than_a_zero_one(): void
    {
        $limits = ConversionLimits::fromConfig(
            $this->config(['daily_limit' => null, 'monthly_limit' => null]),
            Currency::THB,
        );

        $this->assertFalse($limits->hasDailyLimit());
        $this->assertFalse($limits->hasMonthlyLimit());
        $this->assertNull($limits->remainingDaily('999999.00'));
        $this->assertSame([], $limits->rollingViolations('500000.00', '499999.00'));
    }

    #[Test]
    public function the_remaining_head_room_is_never_negative(): void
    {
        $limits = ConversionLimits::fromConfig($this->config(['daily_limit' => '1000.00']), Currency::THB);

        $this->assertSame('100.00', (string) $limits->remainingDaily('900.00'));

        // A total already past the ceiling would otherwise produce "-100.00",
        // which every panel would then render as a negative allowance.
        $this->assertSame('0.00', (string) $limits->remainingDaily('1100.00'));
    }

    #[Test]
    public function the_whole_shape_survives_a_round_trip_to_the_api(): void
    {
        $limits = ConversionLimits::fromConfig($this->config(), Currency::THB);
        $array = $limits->toArray();

        $this->assertSame('THB', $array['currency']);
        $this->assertSame('10.00', $array['min_amount']);
        $this->assertSame('500000.00', $array['max_amount']);
        $this->assertSame('1000000.00', $array['daily_limit']);
        $this->assertSame('5000000.00', $array['monthly_limit']);

        $this->assertSame(
            $array,
            json_decode((string) json_encode($limits), true),
            'A limits object returned in a JSON response must serialise to these fields, not to {}. '
                .'An empty object here is a field the API client cannot read.',
        );
    }

    #[Test]
    public function the_limits_are_read_in_the_currency_they_will_be_charged_in(): void
    {
        $limits = ConversionLimits::fromConfig($this->config(), Currency::THB);

        $this->assertSame(Currency::THB, $limits->currency());
    }
}
