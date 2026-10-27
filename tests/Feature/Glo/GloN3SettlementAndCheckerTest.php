<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Models\Draw;
use App\Models\DrawResult;
use App\Services\Lottery\GloN3PrizeCalculator;
use App\Services\Lottery\GloN3SaleService;
use App\Services\Lottery\GloN3SettlementService;
use App\Services\Lottery\GloN3TicketChecker;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * GLO-7/9 N3 settlement + ticket checker end-to-end.
 *
 * Settlement writes only draw metadata (numbers + variable baht). It never
 * touches wallets/payouts. Idempotent by fingerprint. Numbers are digit strings.
 */
class GloN3SettlementAndCheckerTest extends TestCase
{
    use DatabaseTruncation;

    private Draw $draw;

    private GloN3SaleService $sales;

    private GloN3SettlementService $settlement;

    private GloN3TicketChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->draw = Draw::factory()->create([
            'status' => 'result_published',
            'scheduled_at' => now()->subDays(5),
            'result_published_at' => now()->subDays(4),
        ]);

        $this->sales = app(GloN3SaleService::class);
        $this->settlement = app(GloN3SettlementService::class);
        $this->checker = app(GloN3TicketChecker::class);

        // Deterministic CSPRNG for settlement in tests.
        $seq = 0;
        app(GloN3PrizeCalculator::class)->setRandomInt(function () use (&$seq): int {
            $v = ($seq * 7 + 3) % 1000;
            $seq++;

            return $v;
        });
    }

    public function test_settle_without_seat_rejected(): void
    {
        $this->expectException(\App\Exceptions\GloSalesException::class);
        $this->settlement->settle($this->draw);
    }

    public function test_settle_conflicted_seat_rejected(): void
    {
        $seat = $this->sales->seatSales($this->draw, ['seats_sold' => 100]);
        $this->sales->flagConflict($seat, 'GLON3_SALES_CONFLICT');

        $this->expectException(\App\Exceptions\GloSalesException::class);
        $this->settlement->settle($this->draw);
    }

    public function test_settle_seats_variable_pool_numbers(): void
    {
        $this->sales->seatSales($this->draw, ['seats_sold' => 500]);
        $outcome = $this->settlement->settle($this->draw);

        $this->assertSame('settled', $outcome['status']);
        $this->assertFalse($outcome['idempotent']);
        $this->assertSame('6000.00', $outcome['pool']);
        $this->assertArrayHasKey('special', $outcome['numbers']);
        $this->assertArrayHasKey('first', $outcome['numbers']);

        $this->draw->refresh();
        $lane = $this->draw->metadata['glo'] ?? [];
        $this->assertSame('6000.00', $lane['n3_pool'] ?? null);
        $this->assertNotEmpty($lane['n3_fingerprint'] ?? '');
        $this->assertNotEmpty($lane['n3_numbers'] ?? []);

        // Per-winner amounts are pool-derived decimal strings.
        foreach ($lane['n3_per_winner'] as $amount) {
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $amount);
        }
    }

    public function test_settle_is_idempotent_on_same_pool(): void
    {
        $this->sales->seatSales($this->draw, ['seats_sold' => 500]);
        $first = $this->settlement->settle($this->draw);
        $second = $this->settlement->settle($this->draw);

        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['numbers'], $second['numbers']);
        $this->assertSame($first['pool'], $second['pool']);
    }

    public function test_dry_run_does_not_write_metadata(): void
    {
        $this->sales->seatSales($this->draw, ['seats_sold' => 500]);
        $outcome = $this->settlement->settle($this->draw, null, dryRun: true);

        $this->assertSame('dry_run', $outcome['status']);
        $this->draw->refresh();
        $lane = $this->draw->metadata['glo'] ?? [];
        $this->assertArrayNotHasKey('n3_fingerprint', $lane);
    }

    public function test_checker_matches_seated_n3_result_digit_string(): void
    {
        $this->sales->seatSales($this->draw, ['seats_sold' => 500]);
        $outcome = $this->settlement->settle($this->draw);

        $special = $outcome['numbers']['special'][0];
        $this->assertMatchesRegularExpression('/^\d{3}$/', $special);

        $check = $this->checker->check((int) $this->draw->getKey(), $special);

        $this->assertTrue($check['won']);
        $this->assertContains('special', $check['groups']);
        $this->assertTrue($check['has_n3_result']);
        $this->assertSame($special, $check['ticket_number']);
    }

    public function test_checker_miss_returns_not_won(): void
    {
        $this->sales->seatSales($this->draw, ['seats_sold' => 500]);
        $outcome = $this->settlement->settle($this->draw);

        // Find a number not in any group.
        $used = [];
        foreach ($outcome['numbers'] as $list) {
            foreach ($list as $n) {
                $used[$n] = true;
            }
        }

        $miss = null;
        for ($i = 0; $i < 1000; $i++) {
            $candidate = sprintf('%03d', $i);
            if (! isset($used[$candidate])) {
                $miss = $candidate;
                break;
            }
        }

        $this->assertNotNull($miss);
        $check = $this->checker->check((int) $this->draw->getKey(), $miss);
        $this->assertFalse($check['won']);
        $this->assertSame([], $check['groups']);
    }

    public function test_checker_rejects_malformed_ticket(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->checker->check((int) $this->draw->getKey(), '7');
    }

    public function test_checker_rejects_four_digit_ticket(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->checker->check((int) $this->draw->getKey(), '1234');
    }

    public function test_checker_without_result_has_no_n3(): void
    {
        $check = $this->checker->check((int) $this->draw->getKey(), '000');
        $this->assertFalse($check['has_n3_result']);
        $this->assertFalse($check['won']);
    }

    public function test_checker_reads_draw_result_metadata_n3(): void
    {
        DrawResult::create([
            'draw_id' => $this->draw->getKey(),
            'first_prize' => '042042',
            'second_prize' => [],
            'third_prize' => [],
            'metadata' => [
                'glo' => [
                    'n3' => [
                        'special' => ['007'],
                        'first' => ['111'],
                    ],
                ],
            ],
        ]);

        $hit = $this->checker->check((int) $this->draw->getKey(), '007');
        $this->assertTrue($hit['won']);
        $this->assertContains('special', $hit['groups']);

        // Leading-zero equality: 7 recorded as 007 must not match int-cast 7.
        $miss = $this->checker->check((int) $this->draw->getKey(), '008');
        $this->assertFalse($miss['won']);
    }

    public function test_settlement_metadata_group_counts_match_allocator(): void
    {
        $this->sales->seatSales($this->draw, ['seats_sold' => 1000]);
        $outcome = $this->settlement->settle($this->draw);

        $this->assertSame(1, count($outcome['numbers']['special']));
        $this->assertSame(1, count($outcome['numbers']['first']));
        $this->assertSame(1, count($outcome['numbers']['second']));
        $this->assertGreaterThanOrEqual(1, count($outcome['numbers']['third']));
    }
}
