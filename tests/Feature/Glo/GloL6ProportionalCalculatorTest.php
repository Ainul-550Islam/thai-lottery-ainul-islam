<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Models\Draw;
use App\Services\Lottery\GloL6ProportionalPrizeCalculator;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * GLO-7 L6 proportional prize calculator baseline.
 *
 * Verified rules: 80 THB ticket, 48,000,000 full allocation, 14,168 prizes,
 * proportional unsold = sold / 1,000,000. All money is BCMath string decimal.
 */
class GloL6ProportionalCalculatorTest extends TestCase
{
    use DatabaseTruncation;

    private GloL6ProportionalPrizeCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = app(GloL6ProportionalPrizeCalculator::class);
    }

    public function test_ticket_price_is_80_thb_string(): void
    {
        $this->assertSame('80.00', $this->calc->ticketPrice());
    }

    public function test_full_allocation_is_48m(): void
    {
        $this->assertSame('48000000.00', $this->calc->fullAllocation());
    }

    public function test_total_prize_count_is_14168(): void
    {
        $this->assertSame(14168, $this->calc->totalPrizeCount());
    }

    public function test_full_sellout_fraction_is_one(): void
    {
        $this->assertSame('1.000000000000', $this->calc->soldFraction(1000000, 1000000));
    }

    public function test_zero_sold_fraction(): void
    {
        $this->assertSame('0.000000000000', $this->calc->soldFraction(0, 1000000));
    }

    public function test_half_sellout_fraction(): void
    {
        $this->assertSame('0.500000000000', $this->calc->soldFraction(500000, 1000000));
    }

    public function test_over_capacity_rejected(): void
    {
        $this->expectException(\App\Exceptions\GloSalesException::class);
        $this->calc->soldFraction(1000001, 1000000);
    }

    public function test_negative_units_rejected(): void
    {
        $this->expectException(\App\Exceptions\GloSalesException::class);
        $this->calc->soldFraction(-1, 1000000);
    }

    public function test_full_sellout_allocated_gross_equals_full_allocation(): void
    {
        $this->assertSame('48000000.00', $this->calc->allocatedGross(1000000, 1000000));
    }

    public function test_half_sellout_allocated_gross_is_half(): void
    {
        $this->assertSame('24000000.00', $this->calc->allocatedGross(500000, 1000000));
    }

    public function test_zero_sellout_allocated_gross_is_zero(): void
    {
        $this->assertSame('0.00', $this->calc->allocatedGross(0, 1000000));
    }

    public function test_gross_for_units_is_price_times_units(): void
    {
        $this->assertSame('80000.00', $this->calc->grossForUnits(1000));
        $this->assertSame('0.00', $this->calc->grossForUnits(0));
        $this->assertSame('80.00', $this->calc->grossForUnits(1));
    }

    public function test_negative_units_gross_rejected(): void
    {
        $this->expectException(\App\Exceptions\GloSalesException::class);
        $this->calc->grossForUnits(-5);
    }

    public function test_proportional_breakdown_at_full_sellout(): void
    {
        $breakdown = $this->calc->proportionalBreakdown(1000000, 1000000);

        $this->assertSame(1000000, $breakdown['units_sold']);
        $this->assertSame('1.000000000000', $breakdown['fraction']);
        $this->assertSame('48000000.00', $breakdown['allocated_gross']);
        $this->assertSame('80000000.00', $breakdown['gross_sales']);
        $this->assertSame(14168, $breakdown['total_prize_count']);
        $this->assertNotEmpty($breakdown['tiers']);

        $first = collect($breakdown['tiers'])->firstWhere('tier', 'first');
        $this->assertNotNull($first);
        $this->assertSame('6000000.00', $first['full_pot']);
        $this->assertSame('6000000.00', $first['proportional_pot']);
    }

    public function test_proportional_breakdown_scales_with_unsold(): void
    {
        $half = $this->calc->proportionalBreakdown(500000, 1000000);

        $this->assertSame('0.500000000000', $half['fraction']);
        $this->assertSame('24000000.00', $half['allocated_gross']);
        $this->assertSame('40000000.00', $half['gross_sales']);

        $first = collect($half['tiers'])->firstWhere('tier', 'first');
        $this->assertSame('3000000.00', $first['proportional_pot']);
    }

    public function test_breakdown_never_exceeds_full_allocation(): void
    {
        foreach ([0, 1, 137, 999999, 1000000] as $units) {
            $allocated = $this->calc->allocatedGross($units, 1000000);
            $this->assertTrue(
                bccomp($allocated, '48000000.00', 2) <= 0,
                'allocated '.$allocated.' must not exceed full allocation at units='.$units,
            );
        }
    }

    public function test_proportional_pots_sum_does_not_exceed_allocated_gross(): void
    {
        $breakdown = $this->calc->proportionalBreakdown(250000, 1000000);
        $sum = '0.00';

        foreach ($breakdown['tiers'] as $tier) {
            $sum = bcadd($sum, $tier['proportional_pot'], 2);
        }

        $this->assertTrue(
            bccomp($sum, $breakdown['allocated_gross'], 2) <= 0,
            'tier pots '.$sum.' must fit inside allocated '.$breakdown['allocated_gross'],
        );
    }
}
