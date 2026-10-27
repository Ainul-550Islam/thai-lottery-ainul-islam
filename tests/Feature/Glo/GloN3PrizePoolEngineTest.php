<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Exceptions\GloSalesException;
use App\Services\Lottery\GloN3PrizeCalculator;
use App\Services\Lottery\GloN3PrizePoolAllocator;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * GLO-7 N3 variable prize pool engine.
 *
 * Baseline: 20 THB, pool = 60% of sales, caps ≤30/≤30/≤39, special ≥1%,
 * CSPRNG via injectable random_int, NO fixed payouts (3686/749/531/252116 banned).
 */
class GloN3PrizePoolEngineTest extends TestCase
{
    use DatabaseTruncation;

    private GloN3PrizePoolAllocator $allocator;

    private GloN3PrizeCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = app(GloN3PrizePoolAllocator::class);
        $this->calculator = app(GloN3PrizeCalculator::class);
    }

    public function test_ticket_price_is_20_thb(): void
    {
        $this->assertSame('20.00', $this->allocator->ticketPrice());
    }

    public function test_pool_rate_is_sixty_percent(): void
    {
        $this->assertSame('0.60', $this->allocator->poolRate());
    }

    public function test_pool_from_gross_sales_is_sixty_percent(): void
    {
        $this->assertSame('6000.00', $this->allocator->poolFromGrossSales('10000.00'));
        $this->assertSame('0.00', $this->allocator->poolFromGrossSales('0.00'));
        $this->assertSame('120.00', $this->allocator->poolFromGrossSales('200.00'));
    }

    public function test_non_money_gross_rejected(): void
    {
        $this->expectException(GloSalesException::class);
        $this->allocator->poolFromGrossSales('not-money');
    }

    public function test_non_money_gross_rejected_float_style(): void
    {
        $this->expectException(GloSalesException::class);
        $this->allocator->poolFromGrossSales('1e6');
    }

    public function test_non_positive_pool_rejected(): void
    {
        $this->expectException(GloSalesException::class);
        $this->allocator->allocate('0.00');
    }

    public function test_allocation_honours_group_caps(): void
    {
        $allocation = $this->allocator->allocate('1000.00');

        $this->assertTrue(bccomp($allocation['groups']['first']['amount'], $allocation['caps']['first'], 2) <= 0);
        $this->assertTrue(bccomp($allocation['groups']['second']['amount'], $allocation['caps']['second'], 2) <= 0);
        $this->assertTrue(bccomp($allocation['groups']['third']['amount'], $allocation['caps']['third'], 2) <= 0);
    }

    public function test_special_at_least_one_percent_of_pool(): void
    {
        foreach (['100.00', '999.99', '12345.67', '1000000.00'] as $pool) {
            $allocation = $this->allocator->allocate($pool);
            $onePercent = bcmul($pool, '0.01', 2);

            $this->assertTrue(
                bccomp($allocation['groups']['special']['amount'], $onePercent, 2) >= 0,
                'special must be ≥1% of pool '.$pool,
            );
        }
    }

    public function test_caps_are_30_30_39(): void
    {
        $allocation = $this->allocator->allocate('10000.00');

        $this->assertSame('3000.00', $allocation['caps']['first']);
        $this->assertSame('3000.00', $allocation['caps']['second']);
        $this->assertSame('3900.00', $allocation['caps']['third']);
    }

    public function test_allocation_balances_to_pool_with_reserve(): void
    {
        $pool = '7777.77';
        $allocation = $this->allocator->allocate($pool);

        $distributed = '0.00';

        foreach (['special', 'first', 'second', 'third'] as $group) {
            $distributed = bcadd($distributed, $allocation['groups'][$group]['amount'], 2);
        }

        $this->assertSame($pool, bcadd($distributed, $allocation['reserve'], 2));
        $this->assertTrue(bccomp($allocation['reserve'], '0.00', 2) >= 0);
    }

    public function test_banned_fixed_payouts_never_appear_in_allocation(): void
    {
        $allocation = $this->allocator->allocate('368600.00');
        $encoded = json_encode($allocation);

        foreach (GloN3PrizeCalculator::BANNED_FIXED_PAYOUTS as $banned) {
            // Fixed payout constants must not appear as standalone baht amounts.
            foreach ($allocation['groups'] as $group) {
                $this->assertNotSame($banned, $group['amount'], 'banned fixed payout '.$banned);
            }
        }

        $this->assertIsString($encoded);
    }

    public function test_draw_number_is_three_digit_string_with_leading_zeros(): void
    {
        $seq = [0, 7, 42, 999, 5];
        $i = 0;
        $this->calculator->setRandomInt(function () use (&$seq, &$i): int {
            $n = $seq[$i % count($seq)];
            $i++;

            return $n;
        });

        $first = $this->calculator->drawNumber();
        $second = $this->calculator->drawNumber([$first]);

        $this->assertMatchesRegularExpression('/^\d{3}$/', $first);
        $this->assertMatchesRegularExpression('/^\d{3}$/', $second);
        $this->assertSame('000', $first);
        $this->assertNotSame($first, $second);
        // 7 must be formatted as 007 not 7.
        $this->assertContains($second, ['007', '042', '999', '005']);
    }

    public function test_draw_number_respects_exclude_list(): void
    {
        $call = 0;
        $this->calculator->setRandomInt(function () use (&$call): int {
            // First call returns 100 (will be excluded), subsequent return 101.
            $call++;

            return $call === 1 ? 100 : 101;
        });

        $number = $this->calculator->drawNumber(['100']);
        $this->assertSame('101', $number);
    }

    public function test_matches_is_exact_string_equality_no_int_cast(): void
    {
        $this->assertTrue($this->calculator->matches('007', ['007']));
        $this->assertFalse($this->calculator->matches('007', ['7']));
        $this->assertFalse($this->calculator->matches('7', ['007']));
        $this->assertFalse($this->calculator->matches('007', ['008']));
        $this->assertFalse($this->calculator->matches('07', ['007']));
        $this->assertFalse($this->calculator->matches('abc', ['007']));
    }

    public function test_draw_for_pool_returns_unique_digit_strings_per_group(): void
    {
        // Deterministic CSPRNG: cycle 0..999 uniquely across draws.
        $n = 0;
        $this->calculator->setRandomInt(function () use (&$n): int {
            $v = $n % 1000;
            $n++;

            return $v;
        });

        $result = $this->calculator->drawForPool('10000.00');

        $all = [];

        foreach ($result['numbers'] as $group => $list) {
            $this->assertNotEmpty($list, 'group '.$group.' must have numbers');

            foreach ($list as $number) {
                $this->assertMatchesRegularExpression('/^\d{3}$/', $number);
                $this->assertNotContains($number, $all, 'numbers must be unique across groups');
                $all[] = $number;
            }
        }

        $this->assertSame('pool_variable', $result['engine']);
        $this->assertArrayHasKey('special', $result['numbers']);
        $this->assertArrayHasKey('first', $result['numbers']);
        $this->assertArrayHasKey('second', $result['numbers']);
        $this->assertArrayHasKey('third', $result['numbers']);
    }

    public function test_per_winner_amounts_are_pool_derived_not_fixed(): void
    {
        $n = 500;
        $this->calculator->setRandomInt(function () use (&$n): int {
            $v = $n % 1000;
            $n++;

            return $v;
        });

        $pool = '50000.00';
        $result = $this->calculator->drawForPool($pool);

        foreach ($result['per_winner'] as $group => $amount) {
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $amount);
            $this->assertNotContains($amount, GloN3PrizeCalculator::BANNED_FIXED_PAYOUTS);
            // Per-winner cannot exceed the whole pool.
            $this->assertTrue(bccomp($amount, $pool, 2) <= 0);
        }

        // Per-winner × count ≤ group amount (floor division).
        foreach (['special', 'first', 'second', 'third'] as $group) {
            $count = (string) count($result['numbers'][$group]);
            $product = bcmul($result['per_winner'][$group], $count, 2);
            $this->assertTrue(
                bccomp($product, $result['allocation']['groups'][$group]['amount'], 2) <= 0,
                'per_winner product must not exceed group amount for '.$group,
            );
        }
    }

    public function test_calculate_from_gross_sales_end_to_end(): void
    {
        $n = 10;
        $this->calculator->setRandomInt(function () use (&$n): int {
            $v = $n % 1000;
            $n++;

            return $v;
        });

        $result = $this->calculator->calculateFromGrossSales('10000.00');

        $this->assertSame('6000.00', $result['pool']);
        $this->assertSame('10000.00', $result['gross_sales']);
        $this->assertSame('0.60', $result['pool_rate']);
        $this->assertNotEmpty($result['numbers']);
    }

    public function test_sixty_percent_pool_from_one_million_sales(): void
    {
        $this->assertSame('600000.00', $this->allocator->poolFromGrossSales('1000000.00'));
    }

    public function test_allocation_engine_mode_is_pool_variable(): void
    {
        $allocation = $this->allocator->allocate('100.00');
        $this->assertSame('pool_variable', $allocation['engine']);
    }

    public function test_zero_pool_rejected_for_allocation(): void
    {
        $this->expectException(GloSalesException::class);
        $this->allocator->allocate('0');
    }
}
