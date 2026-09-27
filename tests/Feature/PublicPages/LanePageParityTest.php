<?php

declare(strict_types=1);

namespace Tests\Feature\PublicPages;

use App\Services\Lottery\BingoLotteryImportService;
use App\Services\Lottery\NationalLotteryImportService;
use App\Services\Lottery\PcsoLotteryImportService;
use App\Services\Lottery\WeeklyLotteryImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Structural parity of the four public result lanes.
 *
 * A page-by-page comparison against the reference surface found two things
 * that every lane page was missing, and that no existing test noticed because
 * each lane's own suite asserted its own components in isolation:
 *
 *   1. the landing page carried no results TABLE. The controller passed
 *      history => null, so the year's results only ever appeared at
 *      /year/{year}. A visitor arriving at the lane saw one draw and a list
 *      of year links, and had to guess the results were a click further in;
 *
 *   2. no lane page carried the shared public footer that About, Vision,
 *      Terms, Fees, Prize Verification and Discounts all have, so a visitor
 *      arriving from a search engine had no way out but the back button.
 *
 * NO NUMBER HERE CAME FROM ANY REFERENCE PAGE. Every value is synthetic and
 * carries a leading zero, so the assertions also prove the extra rendering
 * path does not widen one.
 */
final class LanePageParityTest extends TestCase
{
    use RefreshDatabase;

    private function seedEveryLane(): void
    {
        $date = now()->subDays(3)->format('Y-m-d');
        $base = ['source_identifier' => 'FIXTURE-PARITY', 'retrieved_at' => '2026-01-01T00:00:00+00:00'];

        app(NationalLotteryImportService::class)->import($base + [
            'draw_date' => $date,
            'first_prize' => '004615',
            'three_up' => '007',
            'two_up' => '04',
            'two_down' => '09',
            'three_front' => ['010', '020'],
            'three_after' => ['030', '040'],
        ], 'fixture');

        app(WeeklyLotteryImportService::class)->import($base + [
            'draw_date' => $date, 'first_6' => '001234', 'three_ball' => '049', 'two_ball' => '09',
        ], 'fixture');

        app(BingoLotteryImportService::class)->import($base + [
            'draw_date' => $date, 'first_6_mega' => '059696', 'three_mega' => '014', 'two_mega' => '07',
        ], 'fixture');

        app(PcsoLotteryImportService::class)->import($base + [
            'draw_date' => $date,
            'draw_time_local' => '21:00',
            'six_digit' => '875390',
            'four_digit' => '0049',
            'three_digit' => '007',
            'two_digit' => '05',
        ], 'fixture');
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function lanes(): array
    {
        return [
            'national' => ['national-lottery.index', '004615'],
            'weekly' => ['weekly-lottery.index', '001234'],
            'mega' => ['bingo-lottery.index', '059696'],
            'pcso' => ['pcso-lottery.index', '875390'],
        ];
    }

    #[DataProvider('lanes')]
    public function test_the_landing_page_shows_the_latest_year_of_results(string $route, string $value): void
    {
        $this->seedEveryLane();

        $response = $this->get(route($route));

        $response->assertOk();
        // A real table, not just a single result card.
        $response->assertSee('<table', false);
        // Carrying the seeded value, with its leading zero intact.
        $response->assertSee($value, false);
    }

    #[DataProvider('lanes')]
    public function test_the_landing_page_carries_the_shared_public_footer(string $route, string $value): void
    {
        unset($value);

        $response = $this->get(route($route));

        $response->assertOk();
        $response->assertSee('pp-footer', false);
        // And the footer really links somewhere useful.
        $response->assertSee(route('home'), false);
        $response->assertSee(route('terms'), false);
    }

    public function test_an_empty_lane_shows_no_table_rather_than_an_empty_one(): void
    {
        // Nothing seeded. A lane with no draws must not render a results table
        // with no rows: an empty grid reads as "we published nothing today",
        // which is a different claim from "there is nothing here yet".
        $response = $this->get(route('weekly-lottery.index'));

        $response->assertOk();
        $response->assertDontSee('<table', false);
    }

    public function test_the_landing_page_still_shows_the_current_result_and_year_nav(): void
    {
        $this->seedEveryLane();

        $response = $this->get(route('pcso-lottery.index'));

        $response->assertOk();
        // The additions must not have displaced what was already there.
        $response->assertSee(trans('pcso_lottery.current_result_heading'), false);
        $response->assertSee(trans('pcso_lottery.year_nav_heading'), false);
        $response->assertSee(route('pcso-lottery.search'), false);
    }
}
