<?php

declare(strict_types=1);

namespace Tests\Feature\NationalLottery;

use App\Enums\DrawPublicationStatus;
use App\Enums\GloSourceState;
use App\Enums\ResultVersionState;
use App\Models\NationalLotteryDraw;
use App\Models\NationalLotteryResult;
use App\Models\NationalLotteryResultVersion;
use App\Models\User;
use App\Services\Lottery\NationalLotteryDateService;
use App\Services\Lottery\NationalLotteryHistoryService;
use App\Services\Lottery\NationalLotteryImportService;
use App\Services\Lottery\NationalLotteryResultService;
use App\Services\Lottery\NationalLotterySearchService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Public National Lottery result surface (PROMPT 5, file 30).
 *
 * NO NUMBER IN THIS FILE CAME FROM ANY COMPETITOR PAGE. Every fixture value
 * is synthetic and chosen to exercise a rule: 000123 proves a six-digit
 * leading-zero round trip, 007 / 04 / 09 prove the short fields, 010 / 020
 * prove that a 3Front list keeps its order and its zeros.
 *
 * FIXTURES ARE BUILT THROUGH THE REAL IMPORT SERVICE, not by inserting rows.
 * A test that hand-writes a published result proves nothing about the code
 * that publishes one; going through the importer means every assertion below
 * also exercises validation, fingerprinting, provenance and publication.
 *
 * The reference page that motivated this work (thailotto.club/national-lottery.php)
 * is named here, in a test comment, only to record WHY these behaviours are
 * required. No markup, text, styling or data from it exists anywhere in this
 * repository.
 */
final class NationalLotteryPublicPageTest extends TestCase
{
    use RefreshDatabase;

    private function importer(): NationalLotteryImportService
    {
        return app(NationalLotteryImportService::class);
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
            'first_prize' => '000123',
            'three_up' => '007',
            'two_up' => '04',
            'two_down' => '09',
            'three_front' => ['010', '020'],
            'three_after' => ['030', '040'],
            'source_identifier' => 'TEST-'.$isoDate,
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
        ], $overrides);

        return $this->importer()->import($payload, $provider);
    }

    /**
     * A real user row, because resolved_by is a foreign key: an invented id
     * would fail the constraint, and a test that works around a constraint
     * is not testing the system that ships.
     */
    private function actorId(): int
    {
        return (int) User::factory()->create()->getKey();
    }

    private function pastDate(int $daysAgo): string
    {
        return app(NationalLotteryDateService::class)->today()->subDays($daysAgo)->format('Y-m-d');
    }

    // =====================================================================
    // 1-8  Page availability, current result selection
    // =====================================================================

    public function test_01_landing_page_is_public_without_login(): void
    {
        $this->get('/national-lottery')->assertOk();
    }

    public function test_02_landing_page_renders_the_translated_heading(): void
    {
        $this->get('/national-lottery')
            ->assertOk()
            ->assertSee(trans('national_lottery.heading'), false);
    }

    public function test_03_landing_page_with_no_data_says_so_and_invents_nothing(): void
    {
        $response = $this->get('/national-lottery');

        $response->assertOk();
        $response->assertSee(trans('national_lottery.empty_current'), false);
        // An empty lane must not render a numbers grid at all.
        $response->assertDontSee('data-nl-field="first_prize"', false);
    }

    public function test_04_landing_page_shows_the_published_current_result(): void
    {
        $this->publish($this->pastDate(3));

        $this->get('/national-lottery')
            ->assertOk()
            ->assertSee('000123', false)
            ->assertDontSee(trans('national_lottery.empty_current'), false);
    }

    public function test_05_six_digit_leading_zeros_survive_to_the_page(): void
    {
        $this->publish($this->pastDate(3), ['first_prize' => '004615']);

        $response = $this->get('/national-lottery');

        $response->assertOk();
        $response->assertSee('004615', false);
        // The truncated integer form must never appear as a rendered value.
        $response->assertDontSee('>4615<', false);
    }

    public function test_06_short_field_leading_zeros_survive_to_the_page(): void
    {
        $this->publish($this->pastDate(3), [
            'three_up' => '007',
            'two_up' => '04',
            'two_down' => '00',
        ]);

        $response = $this->get('/national-lottery');

        $response->assertOk();
        $response->assertSee('007', false);
        $response->assertSee('04', false);
        $response->assertSee('00', false);
    }

    public function test_07_current_result_is_the_latest_draw_date_not_the_highest_id(): void
    {
        // Newest DATE is imported FIRST, so the highest id belongs to the
        // OLDER draw. MAX(id) would answer wrongly here.
        $this->publish($this->pastDate(1), ['first_prize' => '111111']);
        $this->publish($this->pastDate(30), ['first_prize' => '222222']);

        $current = app(NationalLotteryResultService::class)->currentResult();

        $this->assertSame('111111', $current['numbers']['first_prize']);
    }

    public function test_08_a_future_dated_draw_is_never_the_current_result(): void
    {
        $future = app(NationalLotteryDateService::class)->today()->addDays(10)->format('Y-m-d');

        $this->publish($this->pastDate(2), ['first_prize' => '111111']);
        $this->publish($future, ['first_prize' => '999999']);

        $current = app(NationalLotteryResultService::class)->currentResult();

        $this->assertSame('111111', $current['numbers']['first_prize']);
        $this->get('/national-lottery')->assertOk()->assertDontSee('999999', false);
    }

    // =====================================================================
    // 9-13  Publication state, conflicts, detail page
    // =====================================================================

    public function test_09_an_unpublished_draw_is_not_visible_to_the_public(): void
    {
        $outcome = $this->importer()->import([
            'draw_date' => $this->pastDate(4),
            'first_prize' => '555555',
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
        ], 'fixture', null, false);

        $this->assertSame(NationalLotteryImportService::STATUS_IMPORTED, $outcome['status']);
        $this->assertFalse($outcome['published']);

        $this->get('/national-lottery')->assertOk()->assertDontSee('555555', false);
    }

    public function test_10_a_conflicting_payload_blocks_publication_and_keeps_the_verified_result(): void
    {
        $date = $this->pastDate(5);
        $this->publish($date, ['first_prize' => '111111']);

        $conflict = $this->publish($date, [
            'first_prize' => '222222',
            'retrieved_at' => '2026-02-02T00:00:00+00:00',
        ]);

        $this->assertSame(NationalLotteryImportService::STATUS_CONFLICT, $conflict['status']);
        $this->assertFalse($conflict['published']);

        $response = $this->get('/national-lottery');
        $response->assertOk();
        $response->assertSee('111111', false);
        $response->assertDontSee('222222', false);
    }

    public function test_11_detail_page_renders_for_a_published_draw(): void
    {
        $date = $this->pastDate(6);
        $outcome = $this->publish($date);

        $this->get('/national-lottery/'.$outcome['draw_reference'])
            ->assertOk()
            ->assertSee('000123', false)
            ->assertSee(trans('national_lottery.provenance_heading'), false);
    }

    public function test_12_detail_page_for_an_unknown_reference_reports_not_found_without_an_error(): void
    {
        $this->get('/national-lottery/NL-19000101')
            ->assertOk()
            ->assertSee(trans('national_lottery.status.result_not_found'), false);
    }

    public function test_13_detail_route_rejects_a_reference_outside_its_alphabet(): void
    {
        $this->get('/national-lottery/'.urlencode("NL-2026' OR 1=1--"))->assertNotFound();
    }

    // =====================================================================
    // 14-20  History, year navigation, pagination
    // =====================================================================

    public function test_14_year_page_lists_that_year_s_draws(): void
    {
        $date = $this->pastDate(20);
        $year = (int) substr($date, 0, 4);
        $this->publish($date, ['first_prize' => '121212']);

        $this->get('/national-lottery/year/'.$year)
            ->assertOk()
            ->assertSee('121212', false);
    }

    public function test_15_a_year_with_no_data_renders_no_public_data_not_a_fake_success(): void
    {
        $this->publish($this->pastDate(10));

        $response = $this->get('/national-lottery/year/1999');

        $response->assertOk();
        $response->assertSee(trans('national_lottery.empty_year'), false);
        $response->assertDontSee('data-nl-field="first_prize"', false);
    }

    public function test_16_year_navigation_is_built_from_real_data_only(): void
    {
        $date = $this->pastDate(15);
        $gregorian = (int) substr($date, 0, 4);
        $this->publish($date);

        $years = app(NationalLotteryHistoryService::class)->availableYears();

        $this->assertCount(1, $years);
        $this->assertSame($gregorian, $years[0]['gregorian']);
        $this->assertSame($gregorian + 543, $years[0]['buddhist']);
    }

    public function test_17_year_route_rejects_an_unbounded_year(): void
    {
        $this->get('/national-lottery/year/999999999')->assertNotFound();
    }

    public function test_18_a_buddhist_year_in_the_url_resolves_to_the_gregorian_year(): void
    {
        $date = $this->pastDate(25);
        $gregorian = (int) substr($date, 0, 4);
        $this->publish($date, ['first_prize' => '131313']);

        $this->get('/national-lottery/year/'.($gregorian + 543))
            ->assertOk()
            ->assertSee('131313', false);
    }

    public function test_19_history_per_page_can_never_exceed_the_configured_ceiling(): void
    {
        $date = $this->pastDate(12);
        $year = (int) substr($date, 0, 4);
        $this->publish($date);

        $history = app(NationalLotteryHistoryService::class)->historyForYear($year, 1, 100000);

        $this->assertLessThanOrEqual(
            (int) config('national_lottery.page.max_per_page'),
            $history['pagination']['per_page'],
        );
    }

    public function test_20_history_paginates_across_pages(): void
    {
        $year = null;

        for ($i = 1; $i <= 4; $i++) {
            $date = $this->pastDate(30 + $i);
            $year = (int) substr($date, 0, 4);
            $this->publish($date, ['first_prize' => str_pad((string) $i, 6, '0', STR_PAD_LEFT)]);
        }

        $page1 = app(NationalLotteryHistoryService::class)->historyForYear((int) $year, 1, 2);
        $page2 = app(NationalLotteryHistoryService::class)->historyForYear((int) $year, 2, 2);

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
    // 21-29  Search
    // =====================================================================

    public function test_21_search_by_six_digit_number_finds_the_draw(): void
    {
        $this->publish($this->pastDate(7), ['first_prize' => '004615']);

        $this->get('/national-lottery/search?number=004615')
            ->assertOk()
            ->assertSee('004615', false);
    }

    public function test_22_search_preserves_leading_zeros_and_does_not_match_the_integer_form(): void
    {
        $this->publish($this->pastDate(7), ['first_prize' => '004615']);

        $withZeros = app(NationalLotterySearchService::class)->searchByNumber('004615');
        $withoutZeros = app(NationalLotterySearchService::class)->searchByNumber('4615');

        $this->assertSame('RESULT_FOUND', $withZeros['status']);
        // '4615' is four digits: it matches no field width, so it is refused
        // rather than silently widened into '004615'.
        $this->assertSame('INVALID_QUERY', $withoutZeros['status']);
    }

    public function test_23_a_three_digit_term_matches_three_up_three_front_and_three_after(): void
    {
        $this->publish($this->pastDate(8), [
            'three_up' => '007',
            'three_front' => ['010', '020'],
            'three_after' => ['030', '040'],
        ]);

        $search = app(NationalLotterySearchService::class);

        $this->assertSame('RESULT_FOUND', $search->searchByNumber('007')['status']);
        $this->assertSame('RESULT_FOUND', $search->searchByNumber('010')['status']);
        $this->assertSame('RESULT_FOUND', $search->searchByNumber('040')['status']);
        $this->assertSame('RESULT_NOT_FOUND', $search->searchByNumber('999')['status']);
    }

    public function test_24_a_two_digit_term_matches_two_up_and_two_down(): void
    {
        $this->publish($this->pastDate(9), ['two_up' => '04', 'two_down' => '09']);

        $search = app(NationalLotterySearchService::class);

        $this->assertSame('RESULT_FOUND', $search->searchByNumber('04')['status']);
        $this->assertSame('RESULT_FOUND', $search->searchByNumber('09')['status']);
        $this->assertSame('RESULT_NOT_FOUND', $search->searchByNumber('77')['status']);
    }

    public function test_25_search_by_date_finds_the_draw(): void
    {
        $date = $this->pastDate(11);
        $this->publish($date, ['first_prize' => '141414']);

        $this->get('/national-lottery/search?date='.$date)
            ->assertOk()
            ->assertSee('141414', false);
    }

    public function test_26_an_unparseable_date_is_reported_as_an_invalid_query(): void
    {
        $outcome = app(NationalLotterySearchService::class)->searchByDate('next tuesday');

        $this->assertSame('INVALID_QUERY', $outcome['status']);
        $this->assertSame([], $outcome['matches']);
    }

    public function test_27_an_unknown_search_field_is_rejected(): void
    {
        $this->get('/national-lottery/search?number=007&field=first_prize;drop')
            ->assertSessionHasErrors('field');

        $outcome = app(NationalLotterySearchService::class)->searchByNumber('007', 'draw_id');
        $this->assertSame('INVALID_QUERY', $outcome['status']);
    }

    public function test_28_the_search_route_carries_the_named_rate_limiter(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route): bool => $route->getName() === 'national-lottery.search');

        $this->assertNotNull($route);
        $this->assertContains('throttle:national-result-search', $route->gatherMiddleware());
    }

    public function test_29_public_search_never_reveals_a_private_ticket_or_a_person(): void
    {
        $this->publish($this->pastDate(7), ['first_prize' => '004615']);

        $response = $this->get('/national-lottery/search?number=004615');

        $response->assertOk();
        foreach (['@', 'user_id', 'ticket_id', 'wallet', 'national_id', 'phone'] as $forbidden) {
            $response->assertDontSee('"'.$forbidden.'"', false);
        }
    }

    // =====================================================================
    // 30-34  JSON surface
    // =====================================================================

    public function test_30_api_results_endpoint_returns_the_shared_projection(): void
    {
        $this->publish($this->pastDate(3), ['first_prize' => '004615']);

        $this->getJson('/api/v1/national-lottery/results')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.current.numbers.first_prize', '004615');
    }

    public function test_31_api_show_endpoint_returns_one_draw(): void
    {
        $outcome = $this->publish($this->pastDate(3));

        $this->getJson('/api/v1/national-lottery/results/'.$outcome['draw_reference'])
            ->assertOk()
            ->assertJsonPath('data.result.draw.reference', $outcome['draw_reference']);
    }

    public function test_32_api_year_endpoint_returns_history(): void
    {
        $date = $this->pastDate(3);
        $year = (int) substr($date, 0, 4);
        $this->publish($date);

        $this->getJson('/api/v1/national-lottery/results/year/'.$year)
            ->assertOk()
            ->assertJsonPath('data.status', 'RESULT_FOUND');
    }

    public function test_33_api_search_refuses_both_a_number_and_a_date(): void
    {
        $this->getJson('/api/v1/national-lottery/search?number=007&date=2026-01-01')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_query');
    }

    public function test_34_api_search_refuses_an_empty_query(): void
    {
        $this->getJson('/api/v1/national-lottery/search')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_query');
    }

    // =====================================================================
    // 35-40  Provenance, SEO, i18n, integrity guards
    // =====================================================================

    public function test_35_a_fixture_import_is_labelled_fixture_and_never_official(): void
    {
        $this->publish($this->pastDate(3));

        $response = $this->get('/national-lottery');

        $response->assertOk();
        $response->assertSee(trans('national_lottery.source_state.fixture_only'), false);
        $response->assertDontSee(trans('national_lottery.source_state.official_source_verified'), false);

        // The page may not CLAIM official status. It may - and now must -
        // deny it: the shared footer's disclaimer names GLO precisely in
        // order to say this site is not it.
        $response->assertDontSee('is the official GLO', false);
        $response->assertSee('not the official GLO website', false);
    }

    public function test_36_a_fixture_never_inherits_an_official_label_when_official_is_configured(): void
    {
        config(['national_lottery.sources.official.endpoint' => 'https://example.test/feed?token=secret']);

        $outcome = $this->publish($this->pastDate(3));

        $this->assertSame(GloSourceState::FixtureOnly->value, $outcome['source_state']);
        $this->assertFalse((bool) config('national_lottery.sources.fall_through_to_fixture'));
    }

    public function test_37_the_detail_page_publishes_the_full_provenance_record(): void
    {
        $outcome = $this->publish($this->pastDate(3));

        $response = $this->get('/national-lottery/'.$outcome['draw_reference']);

        $response->assertOk();
        // source_state is deliberately absent from this list: the component
        // renders it as the badge (a human label), not as a term/definition
        // pair, and the badge is asserted separately below.
        $response->assertSee(trans('national_lottery.source_state.fixture_only'), false);

        foreach ([
            'provenance.provider',
            'provenance.payload_fingerprint',
            'provenance.normalized_fingerprint',
            'provenance.parser_version',
            'provenance.imported_at',
            'provenance.result_version',
        ] as $key) {
            $response->assertSee(trans('national_lottery.'.$key), false);
        }
    }

    public function test_38_provenance_never_exposes_a_token_an_endpoint_or_an_internal_id(): void
    {
        config(['national_lottery.sources.official.endpoint' => 'https://feed.example.test/v1?token=SUPERSECRET']);

        $outcome = $this->publish($this->pastDate(3), [], 'official');
        $this->assertSame(NationalLotteryImportService::STATUS_IMPORTED, $outcome['status']);

        $response = $this->get('/national-lottery/'.$outcome['draw_reference']);

        $response->assertOk();
        $response->assertDontSee('SUPERSECRET', false);
        $response->assertDontSee('token=', false);
        $response->assertDontSee('/v1?', false);
        // The host alone is enough to say which system spoke.
        $response->assertSee('feed.example.test', false);
    }

    public function test_39_seo_metadata_is_correct_and_claims_nothing_official(): void
    {
        $date = $this->pastDate(3);
        $year = (int) substr($date, 0, 4);
        $this->publish($date);

        $landing = $this->get('/national-lottery');
        $landing->assertOk();
        $landing->assertSee('National Lottery Results', false);
        $landing->assertSee('<link rel="canonical" href="'.rtrim((string) config('app.url'), '/').'/national-lottery">', false);
        // Metadata must not assert official status. The body's disclaimer,
        // which denies it, is a different statement and is asserted by
        // test_35.
        $landing->assertDontSee('is the official GLO', false);
        $landing->assertDontSee('Official Government', false);

        $this->get('/national-lottery/year/'.$year)
            ->assertOk()
            ->assertSee('— '.$year, false);

        $this->get('/national-lottery/search?number=007')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex,follow">', false);
    }

    public function test_40_thai_locale_shows_the_buddhist_year_and_english_shows_the_gregorian_one(): void
    {
        $date = $this->pastDate(3);
        $gregorian = (int) substr($date, 0, 4);
        $outcome = $this->publish($date);

        $this->app->setLocale('th');
        $thai = $this->get('/national-lottery/'.$outcome['draw_reference']);
        $thai->assertOk();
        $thai->assertSee((string) ($gregorian + 543), false);
        $thai->assertSee(trans('national_lottery.heading', [], 'th'), false);

        $this->app->setLocale('en');
        $english = $this->get('/national-lottery/'.$outcome['draw_reference']);
        $english->assertOk();
        $english->assertSee((string) $gregorian, false);
    }

    public function test_41_no_buddhist_offset_arithmetic_exists_in_a_template_or_in_javascript(): void
    {
        $files = [
            base_path('resources/views/national-lottery/index.blade.php'),
            base_path('resources/views/national-lottery/show.blade.php'),
            base_path('resources/views/components/national-lottery/result-table.blade.php'),
            base_path('resources/views/components/national-lottery/result-card.blade.php'),
            base_path('resources/views/components/national-lottery/year-nav.blade.php'),
            base_path('resources/views/components/national-lottery/search-form.blade.php'),
            base_path('resources/views/components/national-lottery/source-status.blade.php'),
            base_path('resources/js/national-lottery.js'),
        ];

        foreach ($files as $file) {
            $this->assertFileExists($file);
            $contents = (string) file_get_contents($file);
            $this->assertStringNotContainsString('543', $contents, basename($file).' performs calendar arithmetic.');
            $this->assertStringNotContainsString('{!!', $contents, basename($file).' emits unescaped output.');
            $this->assertStringNotContainsString('innerHTML', $contents, basename($file).' writes raw HTML.');
        }
    }

    public function test_42_no_competitor_reference_exists_in_the_shipped_lane(): void
    {
        $files = array_merge(
            glob(base_path('app/Services/Lottery/NationalLottery*.php')) ?: [],
            glob(base_path('app/Models/NationalLottery*.php')) ?: [],
            glob(base_path('resources/views/national-lottery/*.blade.php')) ?: [],
            glob(base_path('resources/views/components/national-lottery/*.blade.php')) ?: [],
            [
                base_path('config/national_lottery.php'),
                base_path('lang/en/national_lottery.php'),
                base_path('lang/th/national_lottery.php'),
                base_path('resources/css/national-lottery.css'),
                base_path('resources/js/national-lottery.js'),
            ],
        );

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertStringNotContainsString('thailotto', strtolower($contents), basename($file));
            // The reference page's live numbers must never be baked in.
            foreach (['730640', '536077', '173770'] as $number) {
                $this->assertStringNotContainsString($number, $contents, basename($file));
            }
        }
    }

    public function test_43_a_result_value_is_always_a_string_never_an_integer(): void
    {
        $this->publish($this->pastDate(3), ['first_prize' => '004615', 'three_up' => '007']);

        $result = NationalLotteryResult::query()->where('is_current', true)->firstOrFail();

        $this->assertIsString($result->first_prize);
        $this->assertSame('004615', $result->firstPrize());
        $this->assertSame('007', $result->threeUp());
        $this->assertSame(['010', '020'], $result->threeFront());
    }

    public function test_44_a_published_result_row_is_never_edited_in_place(): void
    {
        $this->publish($this->pastDate(3), ['first_prize' => '004615']);

        $result = NationalLotteryResult::query()->where('is_current', true)->firstOrFail();
        $result->first_prize = '999999';
        $result->save();

        $this->assertSame('004615', (string) $result->fresh()->first_prize);
    }

    public function test_45_a_version_s_provenance_facts_are_immutable_and_undeletable(): void
    {
        $this->publish($this->pastDate(3));

        $version = NationalLotteryResultVersion::query()->firstOrFail();
        $original = (string) $version->payload_fingerprint;

        $version->payload_fingerprint = str_repeat('a', 64);
        $version->save();

        $this->assertSame($original, (string) $version->fresh()->payload_fingerprint);

        $version->delete();
        $this->assertDatabaseHas('national_lottery_result_versions', ['id' => $version->id]);
    }

    public function test_46_a_correction_keeps_the_old_version_and_announces_itself(): void
    {
        $date = $this->pastDate(3);
        $this->publish($date, ['first_prize' => '111111']);

        $conflict = $this->publish($date, [
            'first_prize' => '222222',
            'retrieved_at' => '2026-03-03T00:00:00+00:00',
        ]);
        $this->assertSame(NationalLotteryImportService::STATUS_CONFLICT, $conflict['status']);

        $conflictVersion = NationalLotteryResultVersion::query()
            ->where('state', ResultVersionState::Conflict->value)
            ->firstOrFail();

        $resolution = $this->importer()->resolveConflict($conflictVersion, $this->actorId(), 'Provider reissued the draw sheet.');
        $this->assertSame(NationalLotteryImportService::STATUS_IMPORTED, $resolution['status']);

        // Both versions are retained.
        $this->assertSame(2, NationalLotteryResultVersion::query()->count());
        $this->assertSame(2, NationalLotteryResult::query()->count());
        $this->assertDatabaseHas('national_lottery_result_versions', [
            'version_number' => 1,
            'state' => ResultVersionState::Superseded->value,
        ]);

        $response = $this->get('/national-lottery/'.$resolution['draw_reference']);
        $response->assertOk();
        $response->assertSee('222222', false);
        $response->assertSee(trans('national_lottery.correction_notice', ['version' => 2]), false);
    }

    // =====================================================================
    // Concurrency (6 scenarios)
    // =====================================================================
    // 47-49  Integration seam (PROMPT 7)
    //
    // The lane shipped complete but connected to nothing. These three guard
    // the connections; NationalLotteryIntegrationSeamTest covers them in
    // depth.
    // =====================================================================

    public function test_47_the_page_is_reachable_from_the_guest_navigation(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(route('national-lottery.index'), false);
        $response->assertSee('National Lottery', false);

        // The sibling lane must not have been displaced to make room.
        $response->assertSee(route('weekly-lottery.index'), false);
    }

    public function test_48_robots_blocks_the_search_route_and_only_that_route(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Disallow: /national-lottery/search', $robots);

        // A bare prefix rule would deindex the whole lane.
        $this->assertDoesNotMatchRegularExpression('/^Disallow:\s*\/national-lottery\s*$/m', $robots);
        $this->assertDoesNotMatchRegularExpression('/^Disallow:\s*\/national-lottery\/?\s*$/m', $robots);
        $this->assertStringNotContainsString('Disallow: /national-lottery/year', $robots);

        // robots.txt is a request, not a control: the limiter still stands.
        $this->assertNotNull(config('national_lottery.rate_limit.per_minute'));
    }

    public function test_49_every_config_env_key_is_documented_in_env_example(): void
    {
        $config = (string) file_get_contents(config_path('national_lottery.php'));
        $example = (string) file_get_contents(base_path('.env.example'));

        preg_match_all("/env\(\s*'(NATIONAL_LOTTERY_[A-Z0-9_]+)'/", $config, $matches);

        $keys = array_values(array_unique($matches[1]));
        $this->assertNotEmpty($keys);

        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression(
                '/^#?\s*'.preg_quote($key, '/').'=/m',
                $example,
                $key.' is read by the config but undocumented in .env.example',
            );
        }
    }

    // =====================================================================

    public function test_c1_duplicate_import_creates_exactly_one_version(): void
    {
        $date = $this->pastDate(3);
        $payload = [
            'draw_date' => $date,
            'first_prize' => '000123',
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
        ];

        $first = $this->importer()->import($payload, 'fixture');
        $second = $this->importer()->import($payload, 'fixture');

        $this->assertSame(NationalLotteryImportService::STATUS_IMPORTED, $first['status']);
        $this->assertSame(NationalLotteryImportService::STATUS_DUPLICATE, $second['status']);
        $this->assertSame(1, NationalLotteryResultVersion::query()->count());
    }

    public function test_c2_concurrent_identical_imports_are_serialised_by_the_unique_index(): void
    {
        $date = $this->pastDate(3);
        $this->publish($date);

        $draw = NationalLotteryDraw::query()->firstOrFail();
        $existing = NationalLotteryResultVersion::query()->firstOrFail();

        // Simulate the loser of the race: a second worker that passed the
        // pre-check and reached the insert with the same fingerprint.
        $clash = new NationalLotteryResultVersion;
        $clash->forceFill([
            'draw_id' => $draw->id,
            'version_number' => 99,
            'state' => ResultVersionState::Pending,
            'provider' => 'fixture',
            'source_state' => GloSourceState::FixtureOnly->value,
            'payload_fingerprint' => (string) $existing->payload_fingerprint,
            'normalized_fingerprint' => (string) $existing->normalized_fingerprint,
            'parser_version' => '1',
            'imported_at' => now(),
            'validation_status' => NationalLotteryResultVersion::VALIDATION_PASSED,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        $clash->save();
    }

    public function test_c3_conflicting_concurrent_imports_never_both_publish(): void
    {
        $date = $this->pastDate(3);

        $a = $this->publish($date, ['first_prize' => '111111', 'retrieved_at' => '2026-01-01T00:00:00+00:00']);
        $b = $this->publish($date, ['first_prize' => '222222', 'retrieved_at' => '2026-01-02T00:00:00+00:00']);

        $this->assertTrue($a['published']);
        $this->assertFalse($b['published']);

        $this->assertSame(1, NationalLotteryResult::query()->where('is_current', true)->count());
    }

    public function test_c4_simultaneous_publication_leaves_exactly_one_current_row(): void
    {
        $date = $this->pastDate(3);
        $this->publish($date, ['first_prize' => '111111']);

        $conflictVersion = NationalLotteryResultVersion::query()
            ->where('state', ResultVersionState::Verified->value)
            ->firstOrFail();

        $second = $this->publish($date, [
            'first_prize' => '333333',
            'retrieved_at' => '2026-04-04T00:00:00+00:00',
        ]);
        $this->assertSame(NationalLotteryImportService::STATUS_CONFLICT, $second['status']);

        $pending = NationalLotteryResultVersion::query()
            ->where('state', ResultVersionState::Conflict->value)
            ->firstOrFail();

        $actor = $this->actorId();
        $this->importer()->resolveConflict($pending, $actor, 'First resolution.');
        // A second resolution of the same version must be refused, not
        // applied twice.
        $repeat = $this->importer()->resolveConflict($pending->fresh(), $actor, 'Second resolution.');

        $this->assertSame(NationalLotteryImportService::STATUS_REJECTED, $repeat['status']);
        $this->assertSame(1, NationalLotteryResult::query()->where('is_current', true)->count());
        $this->assertNotSame($conflictVersion->id, (int) NationalLotteryDraw::query()->firstOrFail()->current_result_version_id);
    }

    public function test_c5_simultaneous_public_lookups_agree_with_each_other(): void
    {
        $this->publish($this->pastDate(3), ['first_prize' => '004615']);

        $service = app(NationalLotteryResultService::class);

        $a = $service->currentResult();
        $b = $service->currentResult();
        $page = $this->get('/national-lottery');
        $json = $this->getJson('/api/v1/national-lottery/results');

        $this->assertSame($a, $b);
        $page->assertOk()->assertSee('004615', false);
        $json->assertOk()->assertJsonPath('data.current.numbers.first_prize', '004615');
    }

    public function test_c6_publishing_a_new_version_invalidates_the_cached_projection(): void
    {
        config([
            'national_lottery.cache.enabled' => true,
            'cache.default' => 'array',
        ]);
        Cache::clear();

        $date = $this->pastDate(3);
        $this->publish($date, ['first_prize' => '111111']);

        $service = app(NationalLotteryResultService::class);
        $this->assertSame('111111', $service->currentResult()['numbers']['first_prize']);

        $conflict = $this->publish($date, [
            'first_prize' => '444444',
            'retrieved_at' => '2026-05-05T00:00:00+00:00',
        ]);
        $this->assertSame(NationalLotteryImportService::STATUS_CONFLICT, $conflict['status']);

        $pending = NationalLotteryResultVersion::query()
            ->where('state', ResultVersionState::Conflict->value)
            ->firstOrFail();

        $this->importer()->resolveConflict($pending, $this->actorId(), 'Corrected sheet.');

        // The key carries the version, so the corrected numbers are served
        // immediately rather than after the TTL expires.
        $this->assertSame('444444', app(NationalLotteryResultService::class)->currentResult()['numbers']['first_prize']);
        $this->get('/national-lottery')->assertOk()->assertSee('444444', false)->assertDontSee('111111', false);
    }

    public function test_c7_the_draw_publication_state_stays_publicly_live_across_a_correction(): void
    {
        $date = $this->pastDate(3);
        $this->publish($date, ['first_prize' => '111111']);

        $this->publish($date, ['first_prize' => '555555', 'retrieved_at' => '2026-06-06T00:00:00+00:00']);

        $pending = NationalLotteryResultVersion::query()
            ->where('state', ResultVersionState::Conflict->value)
            ->firstOrFail();

        $this->importer()->resolveConflict($pending, $this->actorId(), 'Corrected sheet.');

        $draw = NationalLotteryDraw::query()->firstOrFail();

        $this->assertSame(DrawPublicationStatus::Published, $draw->publication_status);
        $this->assertTrue($draw->isPubliclyLive());
    }
}
