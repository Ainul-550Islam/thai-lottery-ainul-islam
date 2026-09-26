<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloL6Sale;
use App\Models\GloN3Sale;
use App\Models\User;
use App\Services\Lottery\GloL6SalesService;
use App\Services\Lottery\GloN3SaleService;
use App\Services\Lottery\GloSalesReconciliationService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * GLO-9 sales seat + GLON3_SALES_CONFLICT reconciliation gate.
 *
 * Concurrency: transactions + lockForUpdate + unique seat keys.
 * Money: BCMath strings only. Digit strings for numbers.
 */
class GloSalesSeatAndReconciliationTest extends TestCase
{
    use DatabaseTruncation;

    private Draw $draw;

    private GloN3SaleService $n3Sales;

    private GloL6SalesService $l6Sales;

    private GloSalesReconciliationService $reconciliation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->draw = Draw::factory()->create([
            'status' => 'result_published',
            'scheduled_at' => now()->subDays(7),
            'result_published_at' => now()->subDays(6),
        ]);

        $this->n3Sales = app(GloN3SaleService::class);
        $this->l6Sales = app(GloL6SalesService::class);
        $this->reconciliation = app(GloSalesReconciliationService::class);
    }

    public function test_n3_seat_creates_row_with_computed_pool(): void
    {
        $seat = $this->n3Sales->seatSales($this->draw, [
            'seats_sold' => 500,
        ]);

        $this->assertSame('n3', $seat->product);
        $this->assertSame('open', $seat->seat_state);
        $this->assertSame('10000.00', $seat->gross_sales);
        $this->assertSame('6000.00', $seat->pool_amount);
        $this->assertSame('20.00', $seat->ticket_price);
        $this->assertSame(GloN3Sale::seatKey((int) $this->draw->getKey(), 'n3'), $seat->seat_key);
    }

    public function test_n3_seat_is_unique_per_draw(): void
    {
        $first = $this->n3Sales->seatSales($this->draw, ['seats_sold' => 10]);
        $second = $this->n3Sales->seatSales($this->draw, ['seats_sold' => 20]);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(20, $second->seats_sold);
        $this->assertSame(1, GloN3Sale::query()->where('draw_id', $this->draw->getKey())->count());
    }

    public function test_n3_negative_seats_rejected(): void
    {
        $this->expectException(\App\Exceptions\GloSalesException::class);
        $this->n3Sales->seatSales($this->draw, ['seats_sold' => -1]);
    }

    public function test_n3_explicit_gross_must_match_seats_times_price(): void
    {
        $this->expectException(\App\Exceptions\GloSalesException::class);

        $this->n3Sales->seatSales($this->draw, [
            'seats_sold' => 10,
            'gross_sales' => '999.99',
        ]);
    }

    public function test_n3_close_seat_blocks_further_conflict_free_seats(): void
    {
        $seat = $this->n3Sales->seatSales($this->draw, ['seats_sold' => 5]);
        $closed = $this->n3Sales->closeSeat($seat);

        $this->assertTrue($closed->isClosed());

        $this->expectException(\App\Exceptions\GloSalesException::class);
        $this->n3Sales->seatSales($this->draw, ['seats_sold' => 6]);
    }

    public function test_glon3_sales_conflict_gate_is_seated(): void
    {
        $seat = $this->n3Sales->seatSales($this->draw, ['seats_sold' => 5]);
        $conflicted = $this->n3Sales->flagConflict(
            $seat,
            'GLON3_SALES_CONFLICT',
            'count mismatch vs POS',
        );

        $this->assertSame('conflicted', $conflicted->seat_state);
        $this->assertSame('GLON3_SALES_CONFLICT', $conflicted->conflict_gate);
        $this->assertTrue($conflicted->isConflicted());
        $this->assertSame('count mismatch vs POS', $conflicted->metadata['conflict_reason'] ?? null);
    }

    public function test_conflicted_n3_seat_rejects_new_seats(): void
    {
        $seat = $this->n3Sales->seatSales($this->draw, ['seats_sold' => 5]);
        $this->n3Sales->flagConflict($seat, 'GLON3_SALES_CONFLICT');

        $this->expectException(\App\Exceptions\GloSalesException::class);
        $this->n3Sales->seatSales($this->draw, ['seats_sold' => 9]);
    }

    public function test_conflicted_n3_seat_rejects_close(): void
    {
        $seat = $this->n3Sales->seatSales($this->draw, ['seats_sold' => 5]);
        $this->n3Sales->flagConflict($seat, 'GLON3_SALES_CONFLICT');

        $this->expectException(\App\Exceptions\GloSalesException::class);
        $this->n3Sales->closeSeat($seat);
    }

    public function test_l6_seat_with_capacity_and_gross(): void
    {
        $seat = $this->l6Sales->seatSales($this->draw, [
            'units_sold' => 1000,
        ]);

        $this->assertSame('l6', $seat->product);
        $this->assertSame(1000, $seat->units_sold);
        $this->assertSame(1000000, $seat->units_full);
        $this->assertSame('80000.00', $seat->gross_sales);
        $this->assertSame('80.00', $seat->ticket_price);
    }

    public function test_l6_over_capacity_rejected(): void
    {
        $this->expectException(\App\Exceptions\GloSalesException::class);
        $this->l6Sales->seatSales($this->draw, ['units_sold' => 1000001]);
    }

    public function test_l6_seat_upsert_updates_units(): void
    {
        $this->l6Sales->seatSales($this->draw, ['units_sold' => 10]);
        $again = $this->l6Sales->seatSales($this->draw, ['units_sold' => 25]);

        $this->assertSame(25, $again->units_sold);
        $this->assertSame('2000.00', $again->gross_sales);
        $this->assertSame(1, GloL6Sale::query()->where('draw_id', $this->draw->getKey())->count());
    }

    public function test_reconciliation_matched_when_no_expected_override(): void
    {
        $this->n3Sales->seatSales($this->draw, ['seats_sold' => 100]);
        $outcome = $this->reconciliation->reconcile($this->draw, 'n3');

        $this->assertSame('matched', $outcome['status']);
        $this->assertNull($outcome['conflict_gate']);
        $this->assertFalse($outcome['blocked']);
        $this->assertSame('0.00', $outcome['variance_gross']);
        $this->assertSame('2000.00', $outcome['reconciliation']->recorded_gross);
    }

    public function test_reconciliation_variance_seats_gate(): void
    {
        $this->n3Sales->seatSales($this->draw, ['seats_sold' => 100]);
        $outcome = $this->reconciliation->reconcile($this->draw, 'n3', [
            'expected_seats' => 90,
            'expected_gross' => '1800.00',
        ]);

        $this->assertSame('conflicted', $outcome['status']);
        $this->assertSame('GLON3_SALES_CONFLICT', $outcome['conflict_gate']);
        $this->assertTrue($outcome['blocked']);
        $this->assertSame('200.00', $outcome['variance_gross']);
    }

    public function test_reconciliation_inherits_seat_conflict(): void
    {
        $seat = $this->n3Sales->seatSales($this->draw, ['seats_sold' => 5]);
        $this->n3Sales->flagConflict($seat, 'GLON3_SALES_CONFLICT', 'upstream mismatch');

        $outcome = $this->reconciliation->reconcile($this->draw, 'n3');

        $this->assertSame('conflicted', $outcome['status']);
        $this->assertSame('GLON3_SALES_CONFLICT', $outcome['conflict_gate']);
        $this->assertTrue($outcome['blocked']);
    }

    public function test_reconciliation_l6_matched(): void
    {
        $this->l6Sales->seatSales($this->draw, ['units_sold' => 5000]);
        $outcome = $this->reconciliation->reconcile($this->draw, 'l6');

        $this->assertSame('matched', $outcome['status']);
        $this->assertFalse($outcome['blocked']);
        $this->assertSame('400000.00', $outcome['reconciliation']->recorded_gross);
    }

    public function test_reconciliation_l6_money_variance_conflicts(): void
    {
        $this->l6Sales->seatSales($this->draw, ['units_sold' => 5000]);
        $outcome = $this->reconciliation->reconcile($this->draw, 'l6', [
            'expected_gross' => '399900.00',
        ]);

        $this->assertSame('conflicted', $outcome['status']);
        $this->assertSame('GLON3_SALES_CONFLICT', $outcome['conflict_gate']);
        $this->assertSame('100.00', $outcome['variance_gross']);
    }

    public function test_reconcile_draw_both_products(): void
    {
        $this->n3Sales->seatSales($this->draw, ['seats_sold' => 10]);
        $this->l6Sales->seatSales($this->draw, ['units_sold' => 20]);

        $both = $this->reconciliation->reconcileDraw($this->draw);

        $this->assertNotNull($both['n3']);
        $this->assertNotNull($both['l6']);
        $this->assertSame('matched', $both['n3']['status']);
        $this->assertSame('matched', $both['l6']['status']);
    }

    public function test_reconcile_draw_skips_missing_seats(): void
    {
        $both = $this->reconciliation->reconcileDraw($this->draw);
        $this->assertNull($both['n3']);
        $this->assertNull($both['l6']);
    }

    public function test_unknown_product_rejected(): void
    {
        $this->expectException(\App\Exceptions\GloSalesException::class);
        $this->reconciliation->reconcile($this->draw, 'keno');
    }

    public function test_l6_proportional_for_seated_draw(): void
    {
        $this->l6Sales->seatSales($this->draw, ['units_sold' => 500000]);
        $breakdown = $this->l6Sales->proportionalForDraw((int) $this->draw->getKey());

        $this->assertSame('0.500000000000', $breakdown['fraction']);
        $this->assertSame('24000000.00', $breakdown['allocated_gross']);
    }

    public function test_l6_proportional_without_seat_rejected(): void
    {
        $this->expectException(\App\Exceptions\GloSalesException::class);
        $this->l6Sales->proportionalForDraw((int) $this->draw->getKey());
    }
}
