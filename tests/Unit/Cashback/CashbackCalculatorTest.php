<?php

declare(strict_types=1);

namespace Tests\Unit\Cashback;

use App\Services\Cashback\CashbackCalculator;
use PHPUnit\Framework\TestCase;

final class CashbackCalculatorTest extends TestCase
{
    private CashbackCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new CashbackCalculator;
    }

    public function test_rounding_floors_sub_satang_fractions_without_floats(): void
    {
        // floor(155.00 * (50 + 25) / 10000) = floor(1.1625) at 2dp = 1.16
        $this->assertSame('1.16', $this->calculator->calculateAccrualAmount('155.00', 50, 25, '500.00'));

        // floor(99.99 * 333 / 10000) = floor(3.329667) at 2dp = 3.32 (never rounds up to 3.33)
        $this->assertSame('3.32', $this->calculator->calculateAccrualAmount('99.99', 300, 33, '500.00'));
    }

    public function test_zero_and_negative_net_loss_produce_zero_accrual(): void
    {
        $this->assertSame('0.00', $this->calculator->netLossFromTotals('500.00', '500.00'));
        $this->assertSame('0.00', $this->calculator->netLossFromTotals('500.00', '900.00'));
        $this->assertSame('350.00', $this->calculator->netLossFromTotals('500.00', '150.00'));

        $this->assertSame('0.00', $this->calculator->calculateAccrualAmount('0.00', 100, 50, '500.00'));
        $this->assertSame('0.00', $this->calculator->calculateAccrualAmount('-100.00', 100, 50, '500.00'));
        $this->assertSame('0.00', $this->calculator->calculateAccrualAmount('1000.00', 0, 0, '500.00'));
    }

    public function test_applies_boost_bps_max_per_period_cap_and_min_claim_threshold(): void
    {
        // 1000.00 * (100 + 50) / 10000 = 15.00
        $this->assertSame('15.00', $this->calculator->calculateAccrualAmount('1000.00', 100, 50, '500.00'));

        // Capped at max_per_period = 120.00
        $this->assertSame('120.00', $this->calculator->calculateAccrualAmount('100000.00', 250, 50, '120.00'));

        // min_claim boundary checks
        $this->assertFalse($this->calculator->isClaimableAmount('0.00', '10.00'));
        $this->assertFalse($this->calculator->isClaimableAmount('9.99', '10.00'));
        $this->assertTrue($this->calculator->isClaimableAmount('10.00', '10.00'));
        $this->assertTrue($this->calculator->isClaimableAmount('25.50', '10.00'));
    }
}
