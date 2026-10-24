<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Services\Home\PublicLaneResultDigestService;
use App\Services\Lottery\BingoLotteryImportService;
use App\Services\Lottery\PcsoLotteryImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four public result lanes are visible from the Home page.
 *
 * WHY THIS SUITE EXISTS. Every lane shipped complete, tested and reachable
 * only from the top navigation. A page-by-page comparison against the
 * reference surface found that its home page carries result cards for its
 * lanes while ours carried none, so a visitor landing on the front page had
 * no sign the results existed.
 *
 * NO NUMBER HERE CAME FROM ANY REFERENCE PAGE. The values are synthetic and
 * chosen to prove leading zeros survive the extra hop onto the Home page.
 */
final class HomeLaneResultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_four_lanes_are_listed_on_the_home_page(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(trans('home.lane_results_title'), false);

        foreach ([
            'national-lottery.index',
            'weekly-lottery.index',
            'bingo-lottery.index',
            'pcso-lottery.index',
        ] as $route) {
            $response->assertSee(route($route), false);
        }
    }

    public function test_a_lane_with_no_published_draw_says_so_rather_than_showing_zeros(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(trans('home.lane_results_none'), false);

        // An empty lane must never be rendered as a zero-filled result: each
        // of these is a result a real draw could legitimately produce.
        foreach (['000000', '0000', '000'] as $zeroes) {
            $response->assertDontSee('>'.$zeroes.'<', false);
        }
    }

    public function test_a_published_result_reaches_the_home_page_with_its_leading_zeros(): void
    {
        app(BingoLotteryImportService::class)->import([
            'draw_date' => now()->subDays(2)->format('Y-m-d'),
            'first_6_mega' => '001234',
            'three_mega' => '049',
            'two_mega' => '09',
            'source_identifier' => 'FIXTURE-HOME',
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
        ], 'fixture');

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('001234', false);
        $response->assertSee('049', false);
        // Not widened into 1234 anywhere on the way here.
        $response->assertDontSee('>1234<', false);
    }

    public function test_the_pcso_card_shows_four_categories_and_a_draw_time(): void
    {
        app(PcsoLotteryImportService::class)->import([
            'draw_date' => now()->subDays(2)->format('Y-m-d'),
            'draw_time_local' => '21:00',
            'six_digit' => '875390',
            'four_digit' => '0049',
            'three_digit' => '007',
            'two_digit' => '05',
            'source_identifier' => 'FIXTURE-HOME-PCSO',
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
        ], 'fixture');

        $digest = app(PublicLaneResultDigestService::class)->lanes();

        $pcso = collect($digest['lanes'])->firstWhere('key', 'pcso_lottery');

        $this->assertNotNull($pcso);
        $this->assertTrue($pcso['available']);
        $this->assertSame(
            ['six_digit', 'four_digit', 'three_digit', 'two_digit'],
            array_column($pcso['fields'], 'column'),
        );
        // Several draws share a date in that lane, so the time is part of
        // identifying which result this is.
        $this->assertSame('09:00 PM', $pcso['time_display']);

        $this->get('/')->assertOk()->assertSee('0049', false);
    }

    public function test_the_national_card_omits_its_list_fields(): void
    {
        $digest = app(PublicLaneResultDigestService::class)->lanes();

        $national = collect($digest['lanes'])->firstWhere('key', 'national_lottery');

        $this->assertNotNull($national);

        $columns = array_column($national['fields'], 'column');

        // Scalars belong on a summary card.
        $this->assertContains('first_prize', $columns);
        // 3 Front and 3 After are LISTS of up to four values. Flattening them
        // next to a single headline number would read as though they were the
        // same kind of thing; the lane page presents them properly.
        $this->assertNotContains('three_front', $columns);
        $this->assertNotContains('three_after', $columns);
    }

    public function test_a_disabled_lane_is_not_advertised(): void
    {
        config()->set('pcso_lottery.enabled', false);

        $digest = app(PublicLaneResultDigestService::class)->lanes();

        $this->assertNull(collect($digest['lanes'])->firstWhere('key', 'pcso_lottery'));
        $this->assertCount(3, $digest['lanes']);
    }

    public function test_the_home_page_survives_a_broken_lane(): void
    {
        // A lane whose config is unusable must degrade to its absence, not to
        // a 500 on the page every visitor lands on.
        config()->set('weekly_lottery.fields', 'not-an-array');

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(route('national-lottery.index'), false);
    }
}
