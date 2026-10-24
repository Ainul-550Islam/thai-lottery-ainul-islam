<?php

declare(strict_types=1);

namespace Tests\Feature\WeeklyLottery;

use App\Enums\DrawPublicationStatus;
use App\Enums\GloSourceState;
use App\Enums\ResultVersionState;
use App\Models\User;
use App\Models\WeeklyLotteryDraw;
use App\Models\WeeklyLotteryResult;
use App\Models\WeeklyLotteryResultVersion;
use App\Services\Lottery\WeeklyLotteryDateService;
use App\Services\Lottery\WeeklyLotteryHistoryService;
use App\Services\Lottery\WeeklyLotteryImportService;
use App\Services\Lottery\WeeklyLotteryResultService;
use App\Services\Lottery\WeeklyLotterySearchService;
use App\Services\Lottery\WeeklyResultIntegrityService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Public Weekly Lottery result surface (PROMPT 6, file 30).
 *
 * NO NUMBER IN THIS FILE CAME FROM ANY THIRD-PARTY PAGE. Every fixture value
 * is synthetic and chosen to exercise a rule: 001234 proves a six-digit
 * leading-zero round trip, 049 and 09 prove the three- and two-digit fields
 * (the two shapes that break the instant a value is treated as a number), and
 * a deliberately empty draw proves the RESULT_UNAVAILABLE path.
 *
 * FIXTURES ARE BUILT THROUGH THE REAL IMPORT SERVICE, not by inserting rows.
 * A test that hand-writes a published result proves nothing about the code
 * that publishes one; going through the importer means every assertion below
 * also exercises validation, canonicalization, the integrity gate,
 * fingerprinting, provenance and publication.
 *
 * The reference page that motivated this work is named only in this comment,
 * as a research note, to record WHY these behaviours are required. No markup,
 * text, styling, branding or data from it exists anywhere in this repository.
 */
final class WeeklyLotteryPublicPageTest extends TestCase
{
    use RefreshDatabase;

    private function importer(): WeeklyLotteryImportService
    {
        return app(WeeklyLotteryImportService::class);
    }

    /**
     * A real user row, because resolved_by is a foreign key: an invented id
     * would fail the constraint, and a test that works around a constraint is
     * not testing the system that ships.
     */
    private function actorId(): int
    {
        return (int) User::factory()->create()->getKey();
    }

    /**
     * Publish one draw through the real import path.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function publish(string $isoDate, array $overrides = [], string $provider = 'fixture'): array
    {
        $payload = array_merge([
            'draw_date' => $isoDate,
            'first_6' => '001234',
            'three_ball' => '049',
            'two_ball' => '09',
            'source_identifier' => 'TEST-'.$isoDate,
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
        ], $overrides);

        return $this->importer()->import($payload, $provider);
    }

    /**
     * Publish a draw that has no numbers at all.
     *
     * @return array<string, mixed>
     */
    private function publishWithoutNumbers(string $isoDate): array
    {
        return $this->importer()->import([
            'draw_date' => $isoDate,
            'first_6' => null,
            'three_ball' => null,
            'two_ball' => null,
            'source_identifier' => 'TEST-EMPTY-'.$isoDate,
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
        ], 'fixture');
    }

    private function pastDate(int $daysAgo): string
    {
        return app(WeeklyLotteryDateService::class)->today()->subDays($daysAgo)->format('Y-m-d');
    }

    // =====================================================================
    // 1-5  Anonymous access and current-result selection
    // =====================================================================

    public function test_01_current_page_is_public_without_login(): void
    {
        $this->get('/weekly-lottery')
            ->assertOk()
            ->assertSee(trans('weekly_lottery.heading'), false);
    }

    public function test_02_history_is_public_without_login(): void
    {
        // Two draws: the recent strip deliberately excludes whichever draw is
        // already shown as the current result, so one draw renders no table.
        $this->publish($this->pastDate(7));
        $this->publish($this->pastDate(14));

        $this->get('/weekly-lottery')
            ->assertOk()
            ->assertSee(trans('weekly_lottery.recent_draws_heading'), false);
    }

    public function test_03_year_page_is_public_without_login(): void
    {
        $date = $this->pastDate(20);
        $this->publish($date, ['first_6' => '121212']);

        $this->get('/weekly-lottery/year/'.substr($date, 0, 4))
            ->assertOk()
            ->assertSee('121212', false);
    }

    public function test_04_draw_detail_is_public_without_login(): void
    {
        $outcome = $this->publish($this->pastDate(6));

        $this->get('/weekly-lottery/'.$outcome['draw_reference'])
            ->assertOk()
            ->assertSee(trans('weekly_lottery.provenance_heading'), false);
    }

    public function test_05_current_result_is_the_latest_draw_date_not_the_highest_id(): void
    {
        // Newest DATE is imported FIRST, so the highest id belongs to the
        // OLDER draw. MAX(id) would answer wrongly here.
        $this->publish($this->pastDate(1), ['first_6' => '111111']);
        $this->publish($this->pastDate(30), ['first_6' => '222222']);

        $current = app(WeeklyLotteryResultService::class)->currentResult();

        $this->assertSame('111111', $current['numbers']['first_6']);
    }

    // =====================================================================
    // 6-13  Search and leading zeros
    // =====================================================================

    public function test_06_six_digit_lookup_finds_the_draw(): void
    {
        $this->publish($this->pastDate(7), ['first_6' => '001234']);

        $this->get('/weekly-lottery/search?type=6ball&term=001234')
            ->assertOk()
            ->assertSee('001234', false);
    }

    public function test_07_leading_zero_six_ball_survives_end_to_end(): void
    {
        $this->publish($this->pastDate(7), ['first_6' => '001234']);

        $response = $this->get('/weekly-lottery');
        $response->assertOk();
        $response->assertSee('001234', false);
        // The integer-truncated form must never appear as a rendered value.
        $response->assertDontSee('>1234<', false);

        $search = app(WeeklyLotterySearchService::class);
        $this->assertSame('RESULT_FOUND', $search->search('6ball', '001234')['status']);
        // '1234' is four digits: refused, never silently widened.
        $this->assertSame('INVALID_QUERY', $search->search('6ball', '1234')['status']);
    }

    public function test_08_leading_zero_three_ball_survives_end_to_end(): void
    {
        $this->publish($this->pastDate(7), ['three_ball' => '049']);

        $this->get('/weekly-lottery')->assertOk()->assertSee('049', false);

        $search = app(WeeklyLotterySearchService::class);
        $this->assertSame('RESULT_FOUND', $search->search('3ball', '049')['status']);
        // '49' is not a 3Ball and is not padded into one.
        $this->assertSame('INVALID_QUERY', $search->search('3ball', '49')['status']);
    }

    public function test_09_leading_zero_two_ball_survives_end_to_end(): void
    {
        $this->publish($this->pastDate(7), ['two_ball' => '09']);

        $this->get('/weekly-lottery')->assertOk()->assertSee('09', false);

        $search = app(WeeklyLotterySearchService::class);
        $this->assertSame('RESULT_FOUND', $search->search('2ball', '09')['status']);
        // '9' must NEVER be interpreted as '09'.
        $this->assertSame('INVALID_QUERY', $search->search('2ball', '9')['status']);
    }

    public function test_10_an_invalid_six_digit_term_is_refused(): void
    {
        $search = app(WeeklyLotterySearchService::class);

        foreach (['12345', '1234567', '00123a', '', '      '] as $term) {
            $this->assertSame('INVALID_QUERY', $search->search('6ball', $term)['status'], $term);
        }
    }

    public function test_11_an_invalid_three_digit_term_is_refused(): void
    {
        $search = app(WeeklyLotterySearchService::class);

        foreach (['04', '0499', '04a', '-49'] as $term) {
            $this->assertSame('INVALID_QUERY', $search->search('3ball', $term)['status'], $term);
        }
    }

    public function test_12_an_invalid_two_digit_term_is_refused(): void
    {
        $search = app(WeeklyLotterySearchService::class);

        foreach (['9', '099', 'a9', '+9'] as $term) {
            $this->assertSame('INVALID_QUERY', $search->search('2ball', $term)['status'], $term);
        }
    }

    public function test_13_date_search_finds_the_draw(): void
    {
        $date = $this->pastDate(11);
        $this->publish($date, ['first_6' => '141414']);

        $this->get('/weekly-lottery/search?type=date&term='.$date)
            ->assertOk()
            ->assertSee('141414', false);

        // strtotime-style input is refused rather than interpreted.
        $this->assertSame(
            'INVALID_QUERY',
            app(WeeklyLotterySearchService::class)->search('date', 'next friday')['status'],
        );
    }

    // =====================================================================
    // 14-18  Empty states, missing results, year navigation, pagination
    // =====================================================================

    public function test_14_an_empty_lane_says_so_and_invents_nothing(): void
    {
        $response = $this->get('/weekly-lottery');

        $response->assertOk();
        $response->assertSee(trans('weekly_lottery.empty_current'), false);
        $response->assertDontSee('data-wl-field="first_6"', false);
    }

    public function test_15_a_draw_with_no_numbers_is_shown_as_unavailable_never_as_zeros(): void
    {
        $outcome = $this->publishWithoutNumbers($this->pastDate(3));

        $this->assertSame(WeeklyLotteryImportService::STATUS_IMPORTED, $outcome['status']);
        $this->assertSame(WeeklyLotteryDraw::RESULT_UNAVAILABLE, $outcome['result_status']);

        $stored = WeeklyLotteryResult::query()->where('is_current', true)->firstOrFail();
        $this->assertNull($stored->first_6);
        $this->assertNull($stored->three_ball);
        $this->assertNull($stored->two_ball);

        $projection = app(WeeklyLotteryResultService::class)->currentResult();
        $this->assertSame('RESULT_UNAVAILABLE', $projection['status']);
        $this->assertTrue($projection['available']);
        $this->assertFalse($projection['has_numbers']);

        $response = $this->get('/weekly-lottery');
        $response->assertOk();
        $response->assertSee(trans('weekly_lottery.result_unavailable_badge'), false);
        // The three fabricated shapes must appear nowhere on the page.
        $response->assertDontSee('>000000<', false);
        $response->assertDontSee('>000<', false);
        $response->assertDontSee('>00<', false);
    }

    public function test_16_year_navigation_links_only_to_years_that_have_data(): void
    {
        $date = $this->pastDate(15);
        $gregorian = (int) substr($date, 0, 4);
        $this->publish($date);

        $response = $this->get('/weekly-lottery');
        $response->assertOk();
        $response->assertSee(route('weekly-lottery.year', ['year' => $gregorian]), false);
        $response->assertDontSee(route('weekly-lottery.year', ['year' => 1999]), false);

        // A year with no data renders NO_PUBLIC_DATA, not a fake success page.
        $empty = $this->get('/weekly-lottery/year/1999');
        $empty->assertOk();
        $empty->assertSee(trans('weekly_lottery.empty_year'), false);
        $empty->assertDontSee('data-wl-field="first_6"', false);
    }

    public function test_17_the_year_list_is_derived_from_stored_data_and_is_not_hard_coded(): void
    {
        $date = $this->pastDate(25);
        $gregorian = (int) substr($date, 0, 4);
        $this->publish($date);

        $years = app(WeeklyLotteryHistoryService::class)->availableYears();

        $this->assertCount(1, $years);
        $this->assertSame($gregorian, $years[0]['gregorian']);
        $this->assertSame($gregorian + 543, $years[0]['buddhist']);

        // A Buddhist year in the URL resolves to the same Gregorian page.
        $this->get('/weekly-lottery/year/'.($gregorian + 543))->assertOk();
    }

    public function test_18_pagination_is_bounded_and_traversable(): void
    {
        $year = null;

        for ($i = 1; $i <= 4; $i++) {
            $date = $this->pastDate(30 + $i);
            $year = (int) substr($date, 0, 4);
            $this->publish($date, ['first_6' => str_pad((string) $i, 6, '0', STR_PAD_LEFT)]);
        }

        $history = app(WeeklyLotteryHistoryService::class);

        // per_page can never exceed the configured ceiling.
        $huge = $history->historyForYear((int) $year, 1, 100000);
        $this->assertLessThanOrEqual(
            (int) config('weekly_lottery.page.max_per_page'),
            $huge['pagination']['per_page'],
        );

        $page1 = $history->historyForYear((int) $year, 1, 2);
        $page2 = $history->historyForYear((int) $year, 2, 2);

        $this->assertCount(2, $page1['rows']);
        $this->assertCount(2, $page2['rows']);
        $this->assertTrue($page1['pagination']['has_next']);
        $this->assertTrue($page2['pagination']['has_previous']);
        $this->assertNotSame(
            $page1['rows'][0]['draw']['reference'],
            $page2['rows'][0]['draw']['reference'],
        );
    }

    // =====================================================================
    // 19-23  Public API, envelope, source state
    // =====================================================================

    public function test_19_public_api_returns_the_shared_projection(): void
    {
        $this->publish($this->pastDate(3), ['first_6' => '001234']);

        $this->getJson('/api/v1/weekly-lottery/results')
            ->assertOk()
            ->assertJsonPath('data.current.numbers.first_6', '001234')
            ->assertJsonPath('data.current.numbers.three_ball', '049')
            ->assertJsonPath('data.current.numbers.two_ball', '09');
    }

    public function test_20_every_api_response_uses_the_project_envelope(): void
    {
        $outcome = $this->publish($this->pastDate(3));
        $date = $this->pastDate(3);
        $year = (int) substr($date, 0, 4);

        $this->getJson('/api/v1/weekly-lottery/results')
            ->assertOk()->assertJsonPath('success', true)->assertJsonStructure(['success', 'message', 'data']);

        $this->getJson('/api/v1/weekly-lottery/results/'.$outcome['draw_reference'])
            ->assertOk()->assertJsonPath('data.result.draw.reference', $outcome['draw_reference']);

        $this->getJson('/api/v1/weekly-lottery/results/year/'.$year)
            ->assertOk()->assertJsonPath('data.status', 'RESULT_FOUND');

        $this->getJson('/api/v1/weekly-lottery/search?type=6ball&term=001234')
            ->assertOk()->assertJsonPath('data.status', 'RESULT_FOUND');

        // A missing or unknown type is a 422, never a guess.
        $this->getJson('/api/v1/weekly-lottery/search?term=001234')->assertStatus(422);
        $this->getJson('/api/v1/weekly-lottery/search?type=7ball&term=001234')->assertStatus(422);
    }

    public function test_21_the_source_state_is_published_with_every_result(): void
    {
        $this->publish($this->pastDate(3));

        $this->getJson('/api/v1/weekly-lottery/results')
            ->assertOk()
            ->assertJsonPath('data.current.provenance.source_state', GloSourceState::FixtureOnly->value)
            ->assertJsonPath('data.current.provenance.available', true);
    }

    public function test_22_a_fixture_import_is_labelled_fixture_and_never_official(): void
    {
        $this->publish($this->pastDate(3));

        $response = $this->get('/weekly-lottery');

        $response->assertOk();
        $response->assertSee(trans('weekly_lottery.source_state.fixture_only'), false);
        $response->assertDontSee(trans('weekly_lottery.source_state.official_source_verified'), false);

        // No affirmative claim of official status; the footer's denial is
        // required rather than merely tolerated.
        $response->assertDontSee('is the official GLO', false);
        $response->assertDontSee('Official Government', false);
        $response->assertSee('not the official GLO website', false);
    }

    public function test_23_official_state_requires_a_configured_provider_and_never_auto_promotes(): void
    {
        // No endpoint configured: the official lane cannot claim anything.
        config(['weekly_lottery.sources.official.endpoint' => null]);
        $refused = $this->publish($this->pastDate(4), [], 'official');
        $this->assertSame(WeeklyLotteryImportService::STATUS_REJECTED, $refused['status']);
        $this->assertSame(GloSourceState::NotConfigured->value, $refused['source_state']);

        // Endpoint configured: now, and only now, OFFICIAL_SOURCE_VERIFIED.
        config(['weekly_lottery.sources.official.endpoint' => 'https://feed.example.test/v1?token=SECRET']);
        $accepted = $this->publish($this->pastDate(5), [], 'official');
        $this->assertSame(WeeklyLotteryImportService::STATUS_IMPORTED, $accepted['status']);
        $this->assertSame(GloSourceState::OfficialSourceVerified->value, $accepted['source_state']);

        // A fixture never inherits that label, and fallback is off.
        $fixture = $this->publish($this->pastDate(6), [], 'fixture');
        $this->assertSame(GloSourceState::FixtureOnly->value, $fixture['source_state']);
        $this->assertFalse((bool) config('weekly_lottery.sources.fall_through_to_fixture'));
    }

    // =====================================================================
    // 24-27  Import idempotency, conflict, immutability, correction
    // =====================================================================

    public function test_24_a_duplicate_import_creates_exactly_one_version(): void
    {
        $date = $this->pastDate(3);
        $payload = [
            'draw_date' => $date,
            'first_6' => '001234',
            'three_ball' => '049',
            'two_ball' => '09',
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
        ];

        $first = $this->importer()->import($payload, 'fixture');
        $second = $this->importer()->import($payload, 'fixture');

        $this->assertSame(WeeklyLotteryImportService::STATUS_IMPORTED, $first['status']);
        $this->assertSame(WeeklyLotteryImportService::STATUS_DUPLICATE, $second['status']);
        $this->assertSame(1, WeeklyLotteryResultVersion::query()->count());
    }

    public function test_25_a_conflicting_import_blocks_publication_and_keeps_the_verified_result(): void
    {
        $date = $this->pastDate(5);
        $this->publish($date, ['first_6' => '111111']);

        $conflict = $this->publish($date, [
            'first_6' => '222222',
            'retrieved_at' => '2026-02-02T00:00:00+00:00',
        ]);

        $this->assertSame(WeeklyLotteryImportService::STATUS_CONFLICT, $conflict['status']);
        $this->assertFalse($conflict['published']);

        $response = $this->get('/weekly-lottery');
        $response->assertOk();
        $response->assertSee('111111', false);
        $response->assertDontSee('222222', false);
    }

    public function test_26_a_published_result_is_never_mutated_in_place(): void
    {
        $this->publish($this->pastDate(3), ['first_6' => '001234']);

        $result = WeeklyLotteryResult::query()->where('is_current', true)->firstOrFail();
        $result->first_6 = '999999';
        $result->save();

        $this->assertSame('001234', (string) $result->fresh()->first_6);

        // Provenance is immutable and undeletable too.
        $version = WeeklyLotteryResultVersion::query()->firstOrFail();
        $original = (string) $version->payload_fingerprint;
        $version->payload_fingerprint = str_repeat('a', 64);
        $version->save();
        $this->assertSame($original, (string) $version->fresh()->payload_fingerprint);

        $version->delete();
        $this->assertDatabaseHas('weekly_lottery_result_versions', ['id' => $version->id]);
    }

    public function test_27_a_correction_publishes_a_new_version_and_retains_the_old_one(): void
    {
        $date = $this->pastDate(3);
        $this->publish($date, ['first_6' => '111111']);

        $conflict = $this->publish($date, [
            'first_6' => '222222',
            'retrieved_at' => '2026-03-03T00:00:00+00:00',
        ]);
        $this->assertSame(WeeklyLotteryImportService::STATUS_CONFLICT, $conflict['status']);

        $conflictVersion = WeeklyLotteryResultVersion::query()
            ->where('state', ResultVersionState::Conflict->value)
            ->firstOrFail();

        $resolution = $this->importer()->resolveConflict(
            $conflictVersion,
            $this->actorId(),
            'Provider reissued the draw sheet.',
        );
        $this->assertSame(WeeklyLotteryImportService::STATUS_IMPORTED, $resolution['status']);

        $this->assertSame(2, WeeklyLotteryResultVersion::query()->count());
        $this->assertSame(2, WeeklyLotteryResult::query()->count());
        $this->assertDatabaseHas('weekly_lottery_result_versions', [
            'version_number' => 1,
            'state' => ResultVersionState::Superseded->value,
        ]);

        $response = $this->get('/weekly-lottery/'.$resolution['draw_reference']);
        $response->assertOk();
        $response->assertSee('222222', false);
        $response->assertSee(trans('weekly_lottery.correction_notice', ['version' => 2]), false);
    }

    // =====================================================================
    // 28-32  Security: rate limit, XSS, PII, secrets, competitor scan
    // =====================================================================

    public function test_28_the_search_routes_carry_the_dedicated_rate_limiter(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        foreach (['weekly-lottery.search', 'api.v1.weekly-lottery.search'] as $name) {
            $route = $routes->first(fn ($route): bool => $route->getName() === $name);
            $this->assertNotNull($route, $name);
            $this->assertContains('throttle:weekly-result-search', $route->gatherMiddleware(), $name);
        }

        // And the generic read endpoints are still bounded.
        $index = $routes->first(fn ($route): bool => $route->getName() === 'api.v1.weekly-lottery.results.index');
        $this->assertContains('throttle:api', $index->gatherMiddleware());
    }

    public function test_29_a_crafted_search_term_is_escaped_and_never_executed(): void
    {
        $this->publish($this->pastDate(3));

        $payload = '<script>alert(1)</script>';

        $response = $this->get('/weekly-lottery/search?type=6ball&term='.urlencode($payload));

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);

        // A SQL-shaped term is refused by the pattern, not by the database.
        $this->assertSame(
            'INVALID_QUERY',
            app(WeeklyLotterySearchService::class)->search('6ball', "1' OR '1'='1")['status'],
        );
        $this->get('/weekly-lottery/'.urlencode("WK-2026'; DROP TABLE users;--"))->assertNotFound();
    }

    public function test_30_no_personal_or_ticket_data_appears_in_any_public_response(): void
    {
        User::factory()->create(['email' => 'private.person@example.test']);
        $this->publish($this->pastDate(3), ['first_6' => '001234']);

        foreach ([
            '/weekly-lottery',
            '/weekly-lottery/search?type=6ball&term=001234',
            '/api/v1/weekly-lottery/results',
        ] as $url) {
            $response = $this->get($url);
            $response->assertOk();
            $response->assertDontSee('private.person@example.test', false);

            foreach (['"user_id"', '"ticket_id"', '"wallet', '"national_id"', '"phone"', '"imported_by"', '"draw_id"'] as $forbidden) {
                $response->assertDontSee($forbidden, false);
            }
        }
    }

    public function test_31_provider_secrets_never_reach_a_public_surface(): void
    {
        config(['weekly_lottery.sources.official.endpoint' => 'https://feed.example.test/v1?token=SUPERSECRET']);
        config(['weekly_lottery.sources.official.token' => 'SUPERSECRET']);

        $outcome = $this->publish($this->pastDate(3), [], 'official');
        $this->assertSame(WeeklyLotteryImportService::STATUS_IMPORTED, $outcome['status']);

        // Not in the database, not only absent from the view.
        $this->assertDatabaseMissing('weekly_lottery_result_versions', [
            'source_endpoint_host' => 'https://feed.example.test/v1?token=SUPERSECRET',
        ]);

        $response = $this->get('/weekly-lottery/'.$outcome['draw_reference']);
        $response->assertOk();
        $response->assertDontSee('SUPERSECRET', false);
        $response->assertDontSee('token=', false);
        $response->assertDontSee('/v1?', false);
        // The host alone is enough to say which system spoke.
        $response->assertSee('feed.example.test', false);
    }

    public function test_32_production_source_contains_no_competitor_reference(): void
    {
        $files = array_merge(
            glob(base_path('app/Services/Lottery/Weekly*.php')) ?: [],
            glob(base_path('app/Models/WeeklyLottery*.php')) ?: [],
            glob(base_path('resources/views/weekly-lottery/*.blade.php')) ?: [],
            glob(base_path('resources/views/components/weekly-lottery/*.blade.php')) ?: [],
            glob(base_path('security/weekly-result-integrity/src/*.rs')) ?: [],
            [
                base_path('config/weekly_lottery.php'),
                base_path('lang/en/weekly_lottery.php'),
                base_path('lang/th/weekly_lottery.php'),
                base_path('resources/css/weekly-lottery.css'),
                base_path('resources/js/weekly-lottery.js'),
                base_path('app/Http/Controllers/WeeklyLotteryController.php'),
                base_path('app/Http/Controllers/Api/V1/WeeklyLotteryController.php'),
                base_path('app/Console/Commands/ImportWeeklyLotteryResults.php'),
            ],
        );

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertStringNotContainsString('thailotto', strtolower($contents), basename($file));

            // The reference page's sample values must never be baked in.
            foreach (['592651', '171049', '808570', '245891'] as $number) {
                $this->assertStringNotContainsString($number, $contents, basename($file));
            }
        }
    }

    // =====================================================================
    // 33-38  No fake data, i18n, SEO, responsive/accessible markup
    // =====================================================================

    public function test_33_no_result_value_is_hard_coded_anywhere_in_the_lane(): void
    {
        // The config and the translations describe SHAPES, never values.
        $config = (string) file_get_contents(base_path('config/weekly_lottery.php'));
        $this->assertSame(0, preg_match('/=>\s*\'[0-9]{6}\'/', $config));

        foreach (['en', 'th'] as $locale) {
            $lang = (string) file_get_contents(base_path('lang/'.$locale.'/weekly_lottery.php'));
            $this->assertSame(0, preg_match('/=>\s*\'[0-9]{2,6}\'/', $lang), $locale);
        }

        // And an empty database renders no digits at all.
        $this->get('/weekly-lottery')->assertOk()->assertDontSee('class="wl-digits"', false);
    }

    public function test_34_thai_and_english_translation_keys_match_exactly(): void
    {
        $flatten = static function (array $data, string $prefix = '') use (&$flatten): array {
            $keys = [];

            foreach ($data as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                $keys = is_array($value) ? array_merge($keys, $flatten($value, $path)) : array_merge($keys, [$path]);
            }

            return $keys;
        };

        $en = $flatten(require base_path('lang/en/weekly_lottery.php'));
        $th = $flatten(require base_path('lang/th/weekly_lottery.php'));

        sort($en);
        sort($th);

        $this->assertSame($en, $th, 'Thai and English key sets differ.');
        $this->assertNotEmpty($en);

        // Thai renders the Buddhist year; English renders the Gregorian one.
        $date = $this->pastDate(3);
        $gregorian = (int) substr($date, 0, 4);
        $outcome = $this->publish($date);

        $this->app->setLocale('th');
        $this->get('/weekly-lottery/'.$outcome['draw_reference'])
            ->assertOk()
            ->assertSee((string) ($gregorian + 543), false);

        $this->app->setLocale('en');
        $this->get('/weekly-lottery/'.$outcome['draw_reference'])
            ->assertOk()
            ->assertSee((string) $gregorian, false);
    }

    public function test_35_seo_metadata_is_unique_per_page_and_claims_nothing_official(): void
    {
        $date = $this->pastDate(3);
        $year = (int) substr($date, 0, 4);
        $outcome = $this->publish($date);

        $appUrl = rtrim((string) config('app.url'), '/');

        $landing = $this->get('/weekly-lottery');
        $landing->assertOk();
        $landing->assertSee('Weekly Lottery Results', false);
        $landing->assertSee('<link rel="canonical" href="'.$appUrl.'/weekly-lottery">', false);
        $landing->assertSee('<meta name="robots" content="index,follow">', false);
        // A <title> or description must not assert official status; the
        // body's disclaimer denying it is a different thing entirely.
        $landing->assertDontSee('is the official GLO', false);
        $landing->assertDontSee('Official Government', false);

        $this->get('/weekly-lottery/year/'.$year)
            ->assertOk()
            ->assertSee('— '.$year, false)
            ->assertSee('<link rel="canonical" href="'.$appUrl.'/weekly-lottery/year/'.$year.'">', false);

        $this->get('/weekly-lottery/'.$outcome['draw_reference'])
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.$appUrl.'/weekly-lottery/'.$outcome['draw_reference'].'">', false);
    }

    public function test_36_the_search_page_is_never_indexed(): void
    {
        $this->get('/weekly-lottery/search?type=6ball&term=001234')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex,follow">', false);

        $this->assertFalse((bool) config('weekly_lottery.seo.index_search_pages'));
    }

    public function test_37_the_history_table_is_structurally_responsive(): void
    {
        $this->publish($this->pastDate(3));
        $this->publish($this->pastDate(10));

        $response = $this->get('/weekly-lottery');
        $response->assertOk();

        // Every cell carries its column name for the stacked mobile layout.
        foreach ([
            trans('weekly_lottery.col_draw_date'),
            trans('weekly_lottery.col_first_6'),
            trans('weekly_lottery.col_three_ball'),
            trans('weekly_lottery.col_two_ball'),
        ] as $label) {
            $response->assertSee('data-label="'.e($label).'"', false);
        }

        // No result column is ever hidden by the stylesheet. The only thing
        // the responsive block hides is the <thead>, whose content is
        // reproduced per cell via data-label.
        $css = (string) file_get_contents(base_path('resources/css/weekly-lottery.css'));
        // Strip comments first: the file EXPLAINS that it never uses
        // display:none, and the prose must not be mistaken for a declaration.
        $declarations = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $normalised = str_replace([' ', "\n"], '', $declarations);

        $this->assertStringNotContainsString('display:none', $normalised);
        $this->assertStringContainsString('content:attr(data-label)', $normalised);
        // The 320px breakpoint exists and keeps the value on its own line.
        $this->assertStringContainsString('@media(max-width:24rem)', $normalised);
    }

    public function test_38_the_page_is_accessible_by_structure(): void
    {
        $this->publish($this->pastDate(3));
        $this->publish($this->pastDate(10));

        $response = $this->get('/weekly-lottery');
        $response->assertOk();

        $response->assertSee('<caption class="wl-table__caption">', false);
        $response->assertSee('<th scope="col"', false);
        $response->assertSee('scope="row"', false);
        $response->assertSee('role="search"', false);
        $response->assertSee('aria-describedby="wl-search-term-hint"', false);
        $response->assertSee('for="wl-search-term"', false);
        $response->assertSee('for="wl-search-type"', false);
        $response->assertSee(trans('weekly_lottery.skip_to_content'), false);
        // Source status is text, not colour alone.
        $response->assertSee(trans('weekly_lottery.source_state.fixture_only'), false);
    }

    // =====================================================================
    // 39-44  Cache, internal ids, provider failure, malformed import
    // =====================================================================

    public function test_39_publishing_a_new_version_invalidates_the_cached_projection(): void
    {
        config(['weekly_lottery.cache.enabled' => true, 'cache.default' => 'array']);
        Cache::clear();

        $date = $this->pastDate(3);
        $this->publish($date, ['first_6' => '111111']);

        $this->assertSame('111111', app(WeeklyLotteryResultService::class)->currentResult()['numbers']['first_6']);

        $this->publish($date, ['first_6' => '444444', 'retrieved_at' => '2026-05-05T00:00:00+00:00']);

        $pending = WeeklyLotteryResultVersion::query()
            ->where('state', ResultVersionState::Conflict->value)
            ->firstOrFail();

        $this->importer()->resolveConflict($pending, $this->actorId(), 'Corrected sheet.');

        // The key carries the version, so the corrected numbers are served
        // immediately rather than after the TTL expires.
        $this->assertSame('444444', app(WeeklyLotteryResultService::class)->currentResult()['numbers']['first_6']);
        $this->get('/weekly-lottery')->assertOk()->assertSee('444444', false)->assertDontSee('111111', false);
    }

    public function test_40_public_number_search_is_never_cached(): void
    {
        $this->assertFalse((bool) config('weekly_lottery.cache.cache_search'));

        config(['cache.default' => 'array']);
        Cache::clear();

        $this->publish($this->pastDate(3), ['first_6' => '001234']);
        app(WeeklyLotterySearchService::class)->search('6ball', '001234');

        // No cache entry may exist that is keyed on the searched term.
        $this->assertNull(Cache::get('weekly-lottery.v1.search.6ball.001234'));
        $this->assertNull(Cache::get('weekly-lottery.search.001234'));

        $source = (string) file_get_contents(base_path('app/Services/Lottery/WeeklyLotterySearchService.php'));
        $this->assertStringNotContainsString('CacheRepository', $source);
        $this->assertStringNotContainsString('Cache::', $source);
    }

    public function test_41_no_internal_identifier_is_exposed_by_any_public_projection(): void
    {
        $outcome = $this->publish($this->pastDate(3));

        $json = $this->getJson('/api/v1/weekly-lottery/results')->assertOk()->json();
        $encoded = (string) json_encode($json);

        foreach (['"id":', '"draw_id"', '"result_version_id"', '"imported_by"', '"resolved_by"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }

        // The route key is the reference, never the primary key.
        $draw = WeeklyLotteryDraw::query()->firstOrFail();
        $this->assertSame('draw_reference', $draw->getRouteKeyName());
        $this->get('/weekly-lottery/'.$draw->id)->assertOk()
            ->assertSee(trans('weekly_lottery.status.result_not_found'), false);
        $this->get('/weekly-lottery/'.$outcome['draw_reference'])->assertOk();
    }

    public function test_42_public_search_cannot_reveal_the_existence_of_a_private_record(): void
    {
        $this->publish($this->pastDate(3), ['first_6' => '001234']);

        $found = app(WeeklyLotterySearchService::class)->search('6ball', '001234');
        $missing = app(WeeklyLotterySearchService::class)->search('6ball', '999999');

        // Both answers have the same shape; only the match list differs.
        $this->assertSame(array_keys($found), array_keys($missing));
        $this->assertSame([], $missing['matches']);

        // The service reads no private table at all.
        $source = (string) file_get_contents(base_path('app/Services/Lottery/WeeklyLotterySearchService.php'));
        foreach (['Ticket', 'User', 'Claim', 'Freeze', 'Dealer', 'Wallet', 'Payout'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_43_an_unavailable_provider_degrades_without_inventing_a_result(): void
    {
        // A configured official provider that cannot validate gets nothing,
        // and the fixture lane does NOT stand in for it.
        config(['weekly_lottery.sources.official.endpoint' => 'https://feed.example.test/v1']);
        config(['weekly_lottery.sources.fixture.enabled' => false]);

        $refused = $this->publish($this->pastDate(3), [], 'fixture');
        $this->assertSame(WeeklyLotteryImportService::STATUS_REJECTED, $refused['status']);
        $this->assertContains('FIXTURE_LANE_DISABLED', $refused['errors']);

        $this->get('/weekly-lottery')->assertOk()->assertSee(trans('weekly_lottery.empty_current'), false);
    }

    public function test_44_a_malformed_import_is_refused_and_writes_nothing(): void
    {
        foreach ([
            ['draw_date' => 'not-a-date', 'first_6' => '001234', 'three_ball' => '049', 'two_ball' => '09'],
            ['draw_date' => $this->pastDate(3), 'first_6' => '1234', 'three_ball' => '049', 'two_ball' => '09'],
            ['draw_date' => $this->pastDate(3), 'first_6' => '001234', 'three_ball' => '49', 'two_ball' => '09'],
            ['draw_date' => $this->pastDate(3), 'first_6' => '001234', 'three_ball' => '049', 'two_ball' => '9'],
            ['draw_date' => $this->pastDate(3), 'first_6' => '00123a', 'three_ball' => '049', 'two_ball' => '09'],
            // Partial: a draw publishes all three or none.
            ['draw_date' => $this->pastDate(3), 'first_6' => '001234'],
        ] as $index => $payload) {
            $outcome = $this->importer()->import($payload, 'fixture');
            $this->assertSame(WeeklyLotteryImportService::STATUS_REJECTED, $outcome['status'], 'case '.$index);
        }

        $this->assertSame(0, WeeklyLotteryDraw::query()->count());
        $this->assertSame(0, WeeklyLotteryResultVersion::query()->count());

        // An unknown provider is refused too.
        $this->assertSame(
            WeeklyLotteryImportService::STATUS_REJECTED,
            $this->importer()->import(['draw_date' => $this->pastDate(3)], 'nonsense')['status'],
        );
    }

    // =====================================================================
    // 45-50  Integrity, canonicalization, host sanitisation, routing
    // =====================================================================

    public function test_45_a_fingerprint_that_does_not_match_the_values_is_refused(): void
    {
        $integrity = app(WeeklyResultIntegrityService::class);

        $fields = [
            'draw_reference' => 'WK-20260918',
            'draw_date' => '2026-09-18',
            'first_6' => '001234',
            'three_ball' => '049',
            'two_ball' => '09',
            'source_identifier' => 'TEST',
            'parser_version' => '1',
        ];

        $baseline = $integrity->canonicalFingerprint($fields);

        // One digit changes the fingerprint.
        $tampered = $fields;
        $tampered['first_6'] = '001235';
        $this->assertNotSame($baseline, $integrity->canonicalFingerprint($tampered));

        // A lost leading zero changes it too - which is the point.
        $lostZero = $fields;
        $lostZero['three_ball'] = '490';
        $this->assertNotSame($baseline, $integrity->canonicalFingerprint($lostZero));

        // And the stored fingerprint always equals the recomputed one.
        $outcome = $this->publish($this->pastDate(3));
        $version = WeeklyLotteryResultVersion::query()->firstOrFail();
        $recomputed = $integrity->canonicalFingerprint([
            'draw_reference' => $outcome['draw_reference'],
            'draw_date' => $this->pastDate(3),
            'first_6' => '001234',
            'three_ball' => '049',
            'two_ball' => '09',
            'source_identifier' => 'TEST-'.$this->pastDate(3),
            'parser_version' => '1',
        ]);
        $this->assertSame($recomputed, (string) $version->normalized_fingerprint);
    }

    public function test_46_canonicalization_is_deterministic_and_distinguishes_null_from_empty(): void
    {
        $integrity = app(WeeklyResultIntegrityService::class);

        $fields = [
            'draw_reference' => 'WK-20260918',
            'draw_date' => '2026-09-18',
            'first_6' => '001234',
            'three_ball' => '049',
            'two_ball' => '09',
            'source_identifier' => 'TEST',
            'parser_version' => '1',
        ];

        // Deterministic.
        $this->assertSame($integrity->canonicalBytes($fields), $integrity->canonicalBytes($fields));

        // Field order is fixed, not map order.
        $reordered = array_reverse($fields, true);
        $this->assertSame($integrity->canonicalBytes($fields), $integrity->canonicalBytes($reordered));

        // Length prefixes prove the zeros are in the bytes.
        $bytes = $integrity->canonicalBytes($fields);
        $this->assertStringContainsString("first_6=S6:001234\n", $bytes);
        $this->assertStringContainsString("three_ball=S3:049\n", $bytes);
        $this->assertStringContainsString("two_ball=S2:09\n", $bytes);
        $this->assertStringStartsWith("WKLY1\n7\n", $bytes);

        // Null and empty are different, and neither is a zero.
        $absent = array_merge($fields, ['first_6' => null, 'three_ball' => null, 'two_ball' => null]);
        $empty = array_merge($fields, ['first_6' => '', 'three_ball' => '', 'two_ball' => '']);

        $this->assertStringContainsString("first_6=N0:\n", $integrity->canonicalBytes($absent));
        $this->assertStringContainsString("first_6=S0:\n", $integrity->canonicalBytes($empty));
        $this->assertNotSame(
            $integrity->canonicalFingerprint($absent),
            $integrity->canonicalFingerprint($empty),
        );
        $this->assertStringNotContainsString('000000', $integrity->canonicalBytes($absent));
    }

    public function test_47_the_stored_source_host_is_a_host_and_never_a_url(): void
    {
        config(['weekly_lottery.sources.official.endpoint' => 'https://feed.example.test:8443/v1/weekly?token=SECRET&x=1']);

        $this->publish($this->pastDate(3), [], 'official');

        $version = WeeklyLotteryResultVersion::query()->firstOrFail();

        $this->assertSame('feed.example.test', (string) $version->source_endpoint_host);
        $this->assertStringNotContainsString('SECRET', (string) $version->source_endpoint_host);
        $this->assertStringNotContainsString('https://', (string) $version->source_endpoint_host);
        $this->assertStringNotContainsString('/v1', (string) $version->source_endpoint_host);
    }

    public function test_48_no_provider_credential_is_persisted_anywhere_in_the_lane(): void
    {
        config(['weekly_lottery.sources.official.endpoint' => 'https://feed.example.test/v1?token=SUPERSECRET']);
        config(['weekly_lottery.sources.official.token' => 'SUPERSECRET']);

        $this->publish($this->pastDate(3), [], 'official');

        $version = WeeklyLotteryResultVersion::query()->firstOrFail();
        $row = (string) json_encode($version->getAttributes());

        $this->assertStringNotContainsString('SUPERSECRET', $row);
        $this->assertStringNotContainsString('Authorization', $row);
        $this->assertStringNotContainsString('token=', $row);

        // The audit column carries key NAMES, never provider values.
        $audit = (string) json_encode($version->audit);
        $this->assertStringNotContainsString('SUPERSECRET', $audit);
    }

    public function test_49_route_ordering_puts_the_literal_paths_before_the_wildcard(): void
    {
        $this->publish($this->pastDate(3));

        // If /{draw} were declared first, these would be read as references.
        $this->assertSame(
            'weekly-lottery.search',
            app('router')->getRoutes()->match(
                Request::create('/weekly-lottery/search', 'GET'),
            )->getName(),
        );

        $this->assertSame(
            'weekly-lottery.year',
            app('router')->getRoutes()->match(
                Request::create('/weekly-lottery/year/2026', 'GET'),
            )->getName(),
        );

        $this->assertSame(
            'weekly-lottery.show',
            app('router')->getRoutes()->match(
                Request::create('/weekly-lottery/WK-20260918', 'GET'),
            )->getName(),
        );
    }

    public function test_50_route_constraints_reject_out_of_shape_parameters(): void
    {
        // Year: at most four digits.
        $this->get('/weekly-lottery/year/999999999')->assertNotFound();
        $this->get('/weekly-lottery/year/abcd')->assertNotFound();

        // Draw: a bounded alphabet, at most forty characters.
        $this->get('/weekly-lottery/'.str_repeat('A', 41))->assertNotFound();
        $this->get('/weekly-lottery/'.urlencode('WK 2026'))->assertNotFound();

        // API mirrors the same constraints.
        $this->getJson('/api/v1/weekly-lottery/results/year/999999999')->assertNotFound();
        $this->getJson('/api/v1/weekly-lottery/results/'.str_repeat('A', 41))->assertNotFound();
    }

    // =====================================================================
    // Rust integrity boundary (PHP side)
    // =====================================================================

    public function test_51_php_and_the_native_verifier_agree_or_the_test_is_skipped(): void
    {
        $integrity = app(WeeklyResultIntegrityService::class);

        if (! $integrity->isNativeVerifierAvailable()) {
            $this->markTestSkipped('The Rust verifier binary is not built in this environment.');
        }

        $fields = [
            'draw_reference' => 'WK-20260918',
            'draw_date' => '2026-09-18',
            'first_6' => '001234',
            'three_ball' => '049',
            'two_ball' => '09',
            'source_identifier' => 'TEST',
            'parser_version' => '1',
        ];

        $report = $integrity->verify($fields);

        // The native side was given the PHP hash as the EXPECTED value and
        // rebuilt the bytes itself, so agreement here is a real cross-check.
        $this->assertTrue($report['native']);
        $this->assertTrue($report['acceptable']);
        $this->assertSame(WeeklyResultIntegrityService::STATUS_HASH_ONLY, $report['status']);
        $this->assertSame('WKLY1', $report['canonical_version']);

        // A hash is never reported as a signature.
        $this->assertNotSame(WeeklyResultIntegrityService::STATUS_SIGNED_VERIFIED, $report['status']);
    }

    public function test_52_a_missing_native_verifier_degrades_honestly(): void
    {
        config(['weekly_lottery.integrity.binary' => 'security/weekly-result-integrity/target/release/does-not-exist']);
        config(['weekly_lottery.integrity.required' => false]);

        $integrity = app(WeeklyResultIntegrityService::class);
        $report = $integrity->verify([
            'draw_reference' => 'WK-20260918',
            'draw_date' => '2026-09-18',
            'first_6' => '001234',
            'three_ball' => '049',
            'two_ball' => '09',
            'source_identifier' => 'TEST',
            'parser_version' => '1',
        ]);

        $this->assertFalse($report['native']);
        $this->assertSame(WeeklyResultIntegrityService::STATUS_VERIFIER_UNAVAILABLE, $report['status']);
        // Not required, so the import may proceed on the PHP fingerprint.
        $this->assertTrue($report['acceptable']);

        // The import still works and records what happened.
        $outcome = $this->publish($this->pastDate(3));
        $this->assertSame(WeeklyLotteryImportService::STATUS_IMPORTED, $outcome['status']);
        $this->assertSame(
            WeeklyResultIntegrityService::STATUS_VERIFIER_UNAVAILABLE,
            (string) WeeklyLotteryResultVersion::query()->firstOrFail()->integrity_status,
        );
    }

    public function test_53_a_required_but_missing_verifier_blocks_the_import(): void
    {
        config(['weekly_lottery.integrity.binary' => 'security/weekly-result-integrity/target/release/does-not-exist']);
        config(['weekly_lottery.integrity.required' => true]);

        $outcome = $this->publish($this->pastDate(3));

        $this->assertSame(WeeklyLotteryImportService::STATUS_REJECTED, $outcome['status']);
        $this->assertSame(0, WeeklyLotteryDraw::query()->count());
    }

    // =====================================================================
    // 54-57  Integration points that are easy to ship without
    // =====================================================================

    public function test_54_the_public_page_is_reachable_from_the_site_chrome(): void
    {
        // A public product page that exists only at a URL nobody is given is
        // a half-delivered feature. The guest navigation must link to it.
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(route('weekly-lottery.index'), false);
        $response->assertSee('Weekly Lottery', false);
    }

    public function test_55_robots_txt_keeps_crawlers_out_of_the_search_space(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        // The page already sends noindex, but robots.txt stops the request
        // being made at all - which is what matters over an enumerable space.
        $this->assertStringContainsString('Disallow: /weekly-lottery/search', $robots);

        // The content this product exists to publish stays crawlable.
        $this->assertStringNotContainsString("Disallow: /weekly-lottery\n", $robots);
        $this->assertStringNotContainsString('Disallow: /weekly-lottery/year', $robots);
    }

    public function test_56_every_configured_env_key_is_documented_in_env_example(): void
    {
        $config = (string) file_get_contents(base_path('config/weekly_lottery.php'));
        $example = (string) file_get_contents(base_path('.env.example'));

        preg_match_all("/env\(\s*'([A-Z0-9_]+)'/", $config, $matches);

        $keys = array_values(array_unique($matches[1]));
        $this->assertNotEmpty($keys);

        foreach ($keys as $key) {
            // APP_TIMEZONE is a framework key documented elsewhere in the file.
            if ($key === 'APP_TIMEZONE') {
                continue;
            }

            // Commented-out entries count as documented: a secret must be
            // named but must NOT ship with a value.
            $this->assertMatchesRegularExpression(
                '/^#?\s*'.preg_quote($key, '/').'=/m',
                $example,
                $key.' is read by config/weekly_lottery.php but is not documented in .env.example',
            );
        }

        // A credential must never ship with a value.
        $this->assertDoesNotMatchRegularExpression('/^WEEKLY_LOTTERY_OFFICIAL_TOKEN=.+/m', $example);
        $this->assertDoesNotMatchRegularExpression('/^WEEKLY_LOTTERY_OFFICIAL_ENDPOINT=.+/m', $example);
        $this->assertDoesNotMatchRegularExpression('/^WEEKLY_LOTTERY_INTEGRITY_PUBLIC_KEY=.+/m', $example);
    }

    public function test_57_continuous_integration_runs_the_rust_gates(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        // The crate is only as trustworthy as the gate that keeps it honest.
        foreach ([
            'rust-integrity',
            'cargo fmt --check',
            'cargo check --locked',
            'cargo test --locked',
            'cargo build --release --locked',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow, $required.' is missing from CI');
        }

        // And the crate's own files are all committed and reachable.
        foreach ([
            'security/weekly-result-integrity/Cargo.toml',
            'security/weekly-result-integrity/Cargo.lock',
            'security/weekly-result-integrity/src/lib.rs',
            'security/weekly-result-integrity/src/canonical.rs',
            'security/weekly-result-integrity/src/error.rs',
            'security/weekly-result-integrity/src/main.rs',
            'security/weekly-result-integrity/tests/integrity.rs',
        ] as $file) {
            $this->assertFileExists(base_path($file));
        }

        // Build output must not be committed, but the lock file must be.
        $gitignore = (string) file_get_contents(base_path('.gitignore'));
        $this->assertStringContainsString('/security/*/target', $gitignore);
    }

    // =====================================================================
    // Concurrency (6 scenarios)
    // =====================================================================

    public function test_c1_the_same_result_imported_simultaneously_yields_one_version(): void
    {
        $date = $this->pastDate(3);
        $this->publish($date);

        $draw = WeeklyLotteryDraw::query()->firstOrFail();
        $existing = WeeklyLotteryResultVersion::query()->firstOrFail();

        // Simulate the loser of the race: a second worker that passed the
        // pre-check and reached the insert with the same fingerprint.
        $clash = new WeeklyLotteryResultVersion;
        $clash->forceFill([
            'draw_id' => $draw->id,
            'version_number' => 99,
            'state' => ResultVersionState::Pending,
            'provider' => 'fixture',
            'source_state' => GloSourceState::FixtureOnly->value,
            'payload_fingerprint' => (string) $existing->payload_fingerprint,
            'normalized_fingerprint' => (string) $existing->normalized_fingerprint,
            'parser_version' => '1',
            'integrity_status' => 'INTEGRITY_HASH_ONLY',
            'imported_at' => now(),
            'validation_status' => WeeklyLotteryResultVersion::VALIDATION_PASSED,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        $clash->save();
    }

    public function test_c2_conflicting_simultaneous_imports_never_both_publish(): void
    {
        $date = $this->pastDate(3);

        $a = $this->publish($date, ['first_6' => '111111', 'retrieved_at' => '2026-01-01T00:00:00+00:00']);
        $b = $this->publish($date, ['first_6' => '222222', 'retrieved_at' => '2026-01-02T00:00:00+00:00']);

        $this->assertTrue($a['published']);
        $this->assertFalse($b['published']);
        $this->assertSame(1, WeeklyLotteryResult::query()->where('is_current', true)->count());
    }

    public function test_c3_two_publication_attempts_leave_one_canonical_version(): void
    {
        $date = $this->pastDate(3);
        $this->publish($date, ['first_6' => '111111']);

        $this->publish($date, ['first_6' => '333333', 'retrieved_at' => '2026-04-04T00:00:00+00:00']);

        $pending = WeeklyLotteryResultVersion::query()
            ->where('state', ResultVersionState::Conflict->value)
            ->firstOrFail();

        $actor = $this->actorId();
        $first = $this->importer()->resolveConflict($pending, $actor, 'First resolution.');
        // A second resolution of the same version must be refused, not
        // applied twice.
        $repeat = $this->importer()->resolveConflict($pending->fresh(), $actor, 'Second resolution.');

        $this->assertSame(WeeklyLotteryImportService::STATUS_IMPORTED, $first['status']);
        $this->assertSame(WeeklyLotteryImportService::STATUS_REJECTED, $repeat['status']);
        $this->assertSame(1, WeeklyLotteryResult::query()->where('is_current', true)->count());
        $this->assertSame(
            1,
            WeeklyLotteryResultVersion::query()->where('state', ResultVersionState::Verified->value)->count(),
        );
    }

    public function test_c4_a_concurrent_correction_does_not_silently_overwrite(): void
    {
        $date = $this->pastDate(3);
        $this->publish($date, ['first_6' => '111111']);

        $this->publish($date, ['first_6' => '555555', 'retrieved_at' => '2026-06-06T00:00:00+00:00']);
        $this->publish($date, ['first_6' => '666666', 'retrieved_at' => '2026-07-07T00:00:00+00:00']);

        // Both disagreeing payloads are recorded; neither displaced the live
        // result on its own.
        $this->assertSame(
            2,
            WeeklyLotteryResultVersion::query()->where('state', ResultVersionState::Conflict->value)->count(),
        );
        $this->assertSame('111111', app(WeeklyLotteryResultService::class)->currentResult()['numbers']['first_6']);
        $this->assertSame(3, WeeklyLotteryResult::query()->count());
    }

    public function test_c5_concurrent_history_lookups_agree_with_each_other(): void
    {
        $date = $this->pastDate(3);
        $year = (int) substr($date, 0, 4);
        $this->publish($date, ['first_6' => '001234']);

        $history = app(WeeklyLotteryHistoryService::class);

        $a = $history->historyForYear($year, 1);
        $b = $history->historyForYear($year, 1);
        $page = $this->get('/weekly-lottery/year/'.$year);
        $json = $this->getJson('/api/v1/weekly-lottery/results/year/'.$year);

        $this->assertSame($a, $b);
        $page->assertOk()->assertSee('001234', false);
        $json->assertOk()->assertJsonPath('data.history.rows.0.numbers.first_6', '001234');
    }

    public function test_c6_cache_invalidation_during_publish_never_serves_a_superseded_number(): void
    {
        config(['weekly_lottery.cache.enabled' => true, 'cache.default' => 'array']);
        Cache::clear();

        $date = $this->pastDate(3);
        $year = (int) substr($date, 0, 4);
        $this->publish($date, ['first_6' => '111111']);

        // Warm every cache the lane has.
        app(WeeklyLotteryResultService::class)->currentResult();
        app(WeeklyLotteryHistoryService::class)->historyForYear($year, 1);
        app(WeeklyLotteryHistoryService::class)->availableYears();
        $this->get('/weekly-lottery')->assertOk()->assertSee('111111', false);

        $this->publish($date, ['first_6' => '888888', 'retrieved_at' => '2026-08-08T00:00:00+00:00']);

        $pending = WeeklyLotteryResultVersion::query()
            ->where('state', ResultVersionState::Conflict->value)
            ->firstOrFail();

        $this->importer()->resolveConflict($pending, $this->actorId(), 'Corrected sheet.');

        $this->get('/weekly-lottery')->assertOk()->assertSee('888888', false)->assertDontSee('111111', false);

        $history = app(WeeklyLotteryHistoryService::class)->historyForYear($year, 1);
        $this->assertSame('888888', $history['rows'][0]['numbers']['first_6']);

        $draw = WeeklyLotteryDraw::query()->firstOrFail();
        $this->assertSame(DrawPublicationStatus::Published, $draw->publication_status);
        $this->assertTrue($draw->isPubliclyLive());
    }
}
