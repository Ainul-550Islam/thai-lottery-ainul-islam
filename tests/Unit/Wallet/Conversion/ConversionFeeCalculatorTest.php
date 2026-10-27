<?php

declare(strict_types=1);

namespace Tests\Unit\Wallet\Conversion;

use App\Enums\ConversionFeeBearer;
use App\Enums\WalletConversionDirection;
use App\Services\Wallet\Conversion\ConversionFeeCalculator;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The calculator, driven from a config array this test owns.
 *
 * WHY THE CALCULATOR IS TESTED WITHOUT THE APPLICATION
 * `breakdownFor()` reads exactly one thing — the published fee row — and turns it
 * into four numbers. Booting Laravel to check that arithmetic would make every
 * case below cost a second of setup and would hide the fact that the class has
 * no other dependency. Handing it a config array instead makes each case state
 * its own tariff in the test body, which is what makes the interesting cases
 * (a rule that is switched off, a rate that is unreadable, a rate that rounds)
 * readable at a glance.
 *
 * The last case is the one that matters most in production: a row whose LIVE
 * value has gone missing must fall back to its published rate, and a row with
 * neither must report itself unconfigured so the engine refuses rather than
 * charging a fee of zero.
 */
class ConversionFeeCalculatorTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $feeRow
     */
    private function calculator(array $feeRow, array $extra = []): ConversionFeeCalculator
    {
        return new ConversionFeeCalculator(new Repository(array_merge([
            'fees' => [
                'categories' => [
                    'cash_to_win' => $feeRow,
                    'win_to_cash' => $feeRow,
                ],
            ],
            'conversions' => [
                'fee' => [
                    'bearer' => 'source',
                    'scale' => 2,
                    'rate_scale' => 4,
                    'rule_version_fallback' => '2',
                ],
            ],
        ], $extra)));
    }

    /**
     * @return array<string, mixed>
     */
    private function publishedRow(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'public_visible' => true,
            'calculation' => 'percentage',
            'rate' => '0.0100',
            'currency' => 'THB',
            'rule_version' => '2',
            'execution' => null,
        ], $overrides);
    }

    #[Test]
    public function it_prices_a_conversion_from_the_published_rate(): void
    {
        $breakdown = $this->calculator($this->publishedRow())
            ->breakdownFor(WalletConversionDirection::CashToWin, '100.00', 'THB');

        $this->assertTrue($breakdown->isConfigured);
        $this->assertTrue($breakdown->hasFee());
        $this->assertSame('0.0100', $breakdown->rate);
        $this->assertSame('1.00', $breakdown->percentage);
        $this->assertSame('1.00', $breakdown->feeAmount);
        $this->assertSame('99.00', $breakdown->netAmount);
        $this->assertSame(ConversionFeeBearer::Source, $breakdown->bearer);
        $this->assertSame('cash_to_win', $breakdown->ruleKey);
        $this->assertSame('2', $breakdown->ruleVersion);
    }

    #[Test]
    public function a_live_rule_value_wins_over_the_static_rate(): void
    {
        $calculator = $this->calculator(
            $this->publishedRow(['rate' => '9.9999', 'execution' => 'conversions.fee.execution.percentage']),
            ['conversions' => ['fee' => ['execution' => ['percentage' => '1.00'], 'scale' => 2, 'rate_scale' => 4]]],
        );

        $breakdown = $calculator->breakdownFor(WalletConversionDirection::CashToWin, '100.00', 'THB');

        // The static rate is documentation for the published row; the live value
        // is what the member is charged and what the fees page computes from.
        $this->assertSame('0.0100', $breakdown->rate);
        $this->assertSame('1.00', $breakdown->feeAmount);
    }

    #[Test]
    public function the_live_value_is_read_as_a_whole_percent(): void
    {
        // '5.00' means five percent, not five hundredths of a percent. This is
        // the convention the fees page uses; if the two readings ever diverged,
        // the page would publish 0.05% while the engine charged 5%.
        $calculator = $this->calculator(
            $this->publishedRow(['execution' => 'conversions.fee.execution.percentage']),
            ['conversions' => ['fee' => ['execution' => ['percentage' => '5.00'], 'scale' => 2, 'rate_scale' => 4]]],
        );

        $breakdown = $calculator->breakdownFor(WalletConversionDirection::CashToWin, '100.00', 'THB');

        $this->assertSame('5.00', $breakdown->percentage);
        $this->assertSame('5.00', $breakdown->feeAmount);
        $this->assertSame('95.00', $breakdown->netAmount);
    }

    #[Test]
    public function an_unreadable_live_value_falls_back_to_the_published_rate(): void
    {
        $calculator = $this->calculator(
            $this->publishedRow(['execution' => 'conversions.fee.execution.percentage']),
            ['conversions' => ['fee' => ['execution' => ['percentage' => null], 'scale' => 2, 'rate_scale' => 4]]],
        );

        $breakdown = $calculator->breakdownFor(WalletConversionDirection::CashToWin, '100.00', 'THB');

        $this->assertTrue($breakdown->isConfigured);
        $this->assertSame('0.0100', $breakdown->rate);
        $this->assertSame('1.00', $breakdown->feeAmount);
    }

    #[Test]
    public function a_negative_live_value_is_rejected_rather_than_applied(): void
    {
        // A negative fee would pay the member to convert. The live path refuses
        // it and the published rate stands.
        $calculator = $this->calculator(
            $this->publishedRow(['execution' => 'conversions.fee.execution.percentage']),
            ['conversions' => ['fee' => ['execution' => ['percentage' => '-1.00'], 'scale' => 2, 'rate_scale' => 4]]],
        );

        $breakdown = $calculator->breakdownFor(WalletConversionDirection::CashToWin, '100.00', 'THB');

        $this->assertSame('0.0100', $breakdown->rate);
    }

    #[Test]
    public function a_disabled_rule_is_unconfigured_even_though_it_has_a_rate(): void
    {
        $breakdown = $this->calculator($this->publishedRow(['enabled' => false]))
            ->breakdownFor(WalletConversionDirection::CashToWin, '100.00', 'THB');

        $this->assertFalse($breakdown->isConfigured, 'A switched-off rule must refuse, not charge.');
        $this->assertFalse($breakdown->hasFee());
    }

    #[Test]
    public function a_rule_hidden_from_the_public_page_is_not_charged(): void
    {
        // The published page and the engine read one rule set: a row the member
        // cannot see is a row the member may not be charged from.
        $breakdown = $this->calculator($this->publishedRow(['public_visible' => false]))
            ->breakdownFor(WalletConversionDirection::CashToWin, '100.00', 'THB');

        $this->assertFalse($breakdown->isConfigured);
    }

    #[Test]
    public function a_row_with_no_rate_at_all_is_unconfigured_rather_than_free(): void
    {
        $breakdown = $this->calculator($this->publishedRow(['rate' => '']))
            ->breakdownFor(WalletConversionDirection::CashToWin, '100.00', 'THB');

        $this->assertFalse($breakdown->isConfigured);
        $this->assertFalse($breakdown->hasFee());
        $this->assertSame('100.00', $breakdown->netAmount, 'With no price there is no fee — but the caller must refuse, not proceed.');
    }

    #[Test]
    public function a_zero_amount_prices_to_zero_without_dividing_by_anything(): void
    {
        $breakdown = $this->calculator($this->publishedRow())
            ->breakdownFor(WalletConversionDirection::CashToWin, '0.00', 'THB');

        $this->assertSame('0.00', $breakdown->feeAmount);
        $this->assertSame('0.00', $breakdown->netAmount);
        $this->assertTrue($breakdown->isConfigured, 'A preview at zero is still a configured rule.');
    }

    #[Test]
    public function the_bearer_decides_who_pays_and_is_reported_verbatim(): void
    {
        $breakdown = $this->calculator(
            $this->publishedRow(),
            ['conversions' => ['fee' => ['bearer' => 'destination', 'scale' => 2, 'rate_scale' => 4]]],
        )->breakdownFor(WalletConversionDirection::CashToWin, '100.00', 'THB');

        $this->assertSame(ConversionFeeBearer::Destination, $breakdown->bearer);
    }

    #[Test]
    public function both_directions_are_priced_from_their_own_row(): void
    {
        $calculator = new ConversionFeeCalculator(new Repository([
            'fees' => ['categories' => [
                'cash_to_win' => $this->publishedRow(['rate' => '0.0100']),
                'win_to_cash' => $this->publishedRow(['rate' => '0.0200', 'rule_version' => '2']),
            ]],
            'conversions' => ['fee' => ['bearer' => 'source', 'scale' => 2, 'rate_scale' => 4]],
        ]));

        $toWin = $calculator->breakdownFor(WalletConversionDirection::CashToWin, '100.00', 'THB');
        $toCash = $calculator->breakdownFor(WalletConversionDirection::WinToCash, '100.00', 'THB');

        $this->assertSame('1.00', $toWin->feeAmount);
        $this->assertSame('2.00', $toCash->feeAmount, 'The two directions are priced independently.');
        $this->assertSame('win_to_cash', $toCash->ruleKey);
    }
}
