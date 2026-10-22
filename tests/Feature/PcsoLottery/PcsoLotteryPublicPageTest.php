<?php

declare(strict_types=1);

namespace Tests\Feature\PcsoLottery;

use App\Enums\GloSourceState;
use App\Lottery\Schema\LaneSchemaFactory;
use App\Models\PcsoLotteryDraw;
use App\Models\PcsoLotteryResult;
use App\Models\PcsoLotteryResultVersion;
use App\Models\WeeklyLotteryResultVersion;
use App\Services\Lottery\PcsoLotteryDateService;
use App\Services\Lottery\PcsoLotteryHistoryService;
use App\Services\Lottery\PcsoLotteryImportService;
use App\Services\Lottery\PcsoLotteryResultService;
use App\Services\Lottery\PcsoLotterySearchService;
use App\Services\Lottery\WeeklyLotteryImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Public PCSO Lottery result surface (PROMPT 9, file 30).
 *
 * NO NUMBER IN THIS FILE CAME FROM ANY COMPETITOR PAGE. Every value is
 * synthetic and chosen to exercise a rule: 001234 / 0049 / 007 / 09 prove
 * leading zeros at each of the four widths, 000 and 00 prove that an all-zero
 * value is a REAL result rather than an absent one, and a null proves that
 * "Off" is stored as absence instead.
 *
 * FIXTURES ARE BUILT THROUGH THE REAL IMPORT SERVICE. A test that hand-writes
 * a published row proves nothing about the code that publishes one.
 *
 * WHAT MAKES THIS LANE DIFFERENT, AND THEREFORE WHAT THIS SUITE WATCHES:
 * four categories instead of three, several draws on one calendar date, and a
 * category that may legitimately not run at all.
 */
final class PcsoLotteryPublicPageTest extends TestCase
{
    use RefreshDatabase;

    private function importer(): PcsoLotteryImportService
    {
        return app(PcsoLotteryImportService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function publish(string $isoDate, string $time = '14:00', array $overrides = [], string $provider = 'fixture'): array
    {
        $payload = array_merge([
            'draw_date' => $isoDate,
            'draw_time_local' => $time,
            'six_digit' => '875390',
            'four_digit' => '8359',
            'three_digit' => '075',
            'two_digit' => '05',
            'source_identifier' => 'FIXTURE-'.$isoDate.'-'.$time,
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
        ], $overrides);

        return $this->importer()->import($payload, $provider);
    }

    private function pastDate(int $daysAgo): string
    {
        return app(PcsoLotteryDateService::class)->today()->subDays($daysAgo)->format('Y-m-d');
    }

    // =====================================================================
    // 1-4  Every public route is anonymous
    // =====================================================================

    public function test_01_landing_page_is_public_without_login(): void
    {
        $response = $this->get('/pcso-lottery');

        $response->assertOk();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_02_search_page_is_public_without_login(): void
    {
        $this->get(route('pcso-lottery.search', ['type' => '6d', 'term' => '875390']))->assertOk();
    }

    public function test_03_year_page_is_public_without_login(): void
    {
        $date = $this->pastDate(3);
        $this->publish($date);

        $this->get(route('pcso-lottery.year', ['year' => (int) substr($date, 0, 4)]))->assertOk();
    }

    public function test_04_draw_detail_is_public_without_login(): void
    {
        $outcome = $this->publish($this->pastDate(3));

        $this->get(route('pcso-lottery.show', ['draw' => $outcome['draw_reference']]))->assertOk();
    }

    // =====================================================================
    // 5-8  All four lanes stay discoverable
    // =====================================================================

    public function test_05_guest_navigation_exposes_pcso(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(route('pcso-lottery.index'), false);
        $response->assertSee('PCSO Lottery', false);
    }

    public function test_06_guest_navigation_still_exposes_national(): void
    {
        $this->get('/')->assertOk()->assertSee(route('national-lottery.index'), false);
    }

    public function test_07_guest_navigation_still_exposes_weekly(): void
    {
        $this->get('/')->assertOk()->assertSee(route('weekly-lottery.index'), false);
    }

    public function test_08_guest_navigation_still_exposes_mega(): void
    {
        $this->get('/')->assertOk()->assertSee(route('bingo-lottery.index'), false);
    }

    // =====================================================================
    // 9-10  robots and SEO
    // =====================================================================

    public function test_09_robots_blocks_only_the_pcso_search_route(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Disallow: /pcso-lottery/search', $robots);

        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim((string) $line);

            if (! str_starts_with($line, 'Disallow:')) {
                continue;
            }

            $path = trim(substr($line, strlen('Disallow:')));

            if (str_starts_with($path, '/pcso-lottery')) {
                $this->assertSame('/pcso-lottery/search', $path, 'Only the search route may be blocked, got: '.$path);
            }
        }

        // The three sibling lanes are untouched.
        foreach (['/national-lottery/search', '/weekly-lottery/search', '/bingo-lottery/search'] as $sibling) {
            $this->assertStringContainsString('Disallow: '.$sibling, $robots);
        }
    }

    public function test_10_the_search_page_is_noindex(): void
    {
        $this->get(route('pcso-lottery.search', ['type' => '6d', 'term' => '875390']))
            ->assertOk()
            ->assertSee('noindex,follow', false);
    }

    // =====================================================================
    // 11-12  The wildcard must not swallow its siblings
    // =====================================================================

    public function test_11_the_draw_wildcard_does_not_swallow_search(): void
    {
        $this->assertSame(
            'pcso-lottery.search',
            app('router')->getRoutes()->match(Request::create('/pcso-lottery/search', 'GET'))->getName(),
        );
    }

    public function test_12_the_draw_wildcard_does_not_swallow_year(): void
    {
        $this->assertSame(
            'pcso-lottery.year',
            app('router')->getRoutes()->match(Request::create('/pcso-lottery/year/2026', 'GET'))->getName(),
        );
    }

    // =====================================================================
    // 13-14  Configuration contract
    // =====================================================================

    public function test_13_every_config_env_key_is_documented(): void
    {
        $config = (string) file_get_contents(config_path('pcso_lottery.php'));
        $example = (string) file_get_contents(base_path('.env.example'));

        preg_match_all("/env\(\s*'(PCSO_LOTTERY_[A-Z0-9_]+)'/", $config, $matches);

        $keys = array_values(array_unique($matches[1]));
        $this->assertNotEmpty($keys);

        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression(
                '/^#?\s*'.preg_quote($key, '/').'=/m',
                $example,
                $key.' is read by config/pcso_lottery.php but undocumented in .env.example',
            );
        }
    }

    public function test_14_credentials_are_commented_out_and_empty(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        foreach (['PCSO_LOTTERY_OFFICIAL_ENDPOINT', 'PCSO_LOTTERY_OFFICIAL_TOKEN'] as $secret) {
            $this->assertMatchesRegularExpression('/^#\s*'.preg_quote($secret, '/').'=\s*$/m', $example);
            $this->assertDoesNotMatchRegularExpression('/^'.preg_quote($secret, '/').'=.+/m', $example);
        }

        // No verifier in this lane, so no key may DEFINE one. The prose that
        // explains the absence is allowed to name it.
        $this->assertDoesNotMatchRegularExpression('/^#?\s*PCSO_LOTTERY_INTEGRITY[A-Z_]*=/m', $example);
        $this->assertDoesNotMatchRegularExpression(
            "/env\(\s*'PCSO_LOTTERY_INTEGRITY/",
            (string) file_get_contents(config_path('pcso_lottery.php')),
        );
    }

    // =====================================================================
    // 15  Fixture is never official
    // =====================================================================

    public function test_15_a_fixture_can_never_appear_as_official(): void
    {
        config()->set('pcso_lottery.sources.official.endpoint', 'https://example.invalid/feed');
        config()->set('pcso_lottery.sources.official.token', 'never-logged');

        $outcome = $this->publish($this->pastDate(3), '14:00', [], 'fixture');

        $this->assertSame(GloSourceState::FixtureOnly->value, $outcome['source_state']);

        $response = $this->get('/pcso-lottery');
        $response->assertOk();
        $response->assertDontSee(GloSourceState::OfficialSourceVerified->value, false);
        $response->assertDontSee('never-logged', false);

        $this->assertSame(['official', 'internal', 'replay', 'fixture'], config('pcso_lottery.sources.priority'));
        $this->assertFalse(config('pcso_lottery.sources.fall_through_to_fixture'));
    }

    // =====================================================================
    // 16-19  Leading zeros at every width
    // =====================================================================

    public function test_16_six_digit_leading_zeros_are_preserved(): void
    {
        $this->assertStoredAndRendered('six_digit', '001234');
    }

    public function test_17_four_digit_leading_zeros_are_preserved(): void
    {
        $this->assertStoredAndRendered('four_digit', '0049');
    }

    public function test_18_three_digit_leading_zeros_are_preserved(): void
    {
        $this->assertStoredAndRendered('three_digit', '007');
    }

    public function test_19_two_digit_leading_zeros_are_preserved(): void
    {
        $this->assertStoredAndRendered('two_digit', '09');
    }

    private function assertStoredAndRendered(string $column, string $value): void
    {
        $outcome = $this->publish($this->pastDate(3), '14:00', [$column => $value]);

        $this->assertSame('imported', $outcome['status'], implode(',', $outcome['errors']));

        $draw = PcsoLotteryDraw::query()->where('draw_reference', $outcome['draw_reference'])->firstOrFail();
        $row = $draw->currentResult()->first();

        $this->assertIsString($row->{$column});
        $this->assertSame($value, $row->{$column});

        $this->get(route('pcso-lottery.show', ['draw' => $outcome['draw_reference']]))
            ->assertOk()
            ->assertSee($value, false);
    }

    // =====================================================================
    // 20-22  Off / null semantics
    // =====================================================================

    public function test_20_a_category_that_did_not_run_stays_null(): void
    {
        // The other three ran. This is a REAL PCSO state, not a broken
        // payload, which is why this lane does not require a complete set.
        $outcome = $this->publish($this->pastDate(3), '14:00', ['four_digit' => null]);

        $this->assertSame('imported', $outcome['status'], implode(',', $outcome['errors']));

        $draw = PcsoLotteryDraw::query()->where('draw_reference', $outcome['draw_reference'])->firstOrFail();
        $row = $draw->currentResult()->first();

        $this->assertNull($row->four_digit);
        // And the draw is still published, because three categories did run.
        $this->assertSame(PcsoLotteryDraw::RESULT_PUBLISHED, $draw->result_status);
        $this->assertSame('875390', $row->six_digit);
    }

    public function test_21_off_is_never_stored_as_a_zero(): void
    {
        $outcome = $this->publish($this->pastDate(3), '14:00', ['four_digit' => null]);

        $row = PcsoLotteryResult::query()->where('is_current', true)->firstOrFail();

        foreach (['0', '00', '0000', '000000'] as $zero) {
            $this->assertNotSame($zero, $row->four_digit);
        }

        $this->assertNull($row->four_digit);

        // The page states it in words, not by leaving a cell blank or relying
        // on a colour.
        $this->get(route('pcso-lottery.show', ['draw' => $outcome['draw_reference']]))
            ->assertOk()
            ->assertSee(trans('pcso_lottery.value_off'), false);

        // An all-zero value, by contrast, is a REAL result and must survive.
        $real = $this->publish($this->pastDate(4), '17:00', ['three_digit' => '000', 'two_digit' => '00']);
        $realRow = PcsoLotteryDraw::query()
            ->where('draw_reference', $real['draw_reference'])
            ->firstOrFail()
            ->currentResult()
            ->first();

        $this->assertSame('000', $realRow->three_digit);
        $this->assertSame('00', $realRow->two_digit);
    }

    public function test_22_the_api_keeps_values_as_json_strings_and_marks_absence(): void
    {
        $outcome = $this->publish($this->pastDate(3), '14:00', [
            'six_digit' => '001234',
            'four_digit' => null,
            'three_digit' => '007',
            'two_digit' => '09',
        ]);

        $response = $this->getJson('/api/v1/pcso-lottery/results/'.$outcome['draw_reference']);
        $response->assertOk();

        $body = (string) $response->getContent();

        // Asserted against RAW JSON: json_decode would hand back an int and
        // hide a value that was serialised as a number.
        $this->assertStringContainsString('"001234"', $body);
        $this->assertStringContainsString('"007"', $body);
        $this->assertStringContainsString('"09"', $body);
        $this->assertStringNotContainsString(':1234', $body);

        // Absence is explicit, not an omitted key.
        $decoded = $response->json();
        $this->assertNotNull($decoded);
        $this->assertStringContainsString('null', $body);
    }

    // =====================================================================
    // 23-26  Search widths
    // =====================================================================

    public function test_23_a_malformed_width_is_rejected_not_padded(): void
    {
        foreach ([
            ['six_digit' => '12345'],
            ['four_digit' => '049'],
            ['three_digit' => '07'],
            ['two_digit' => '9'],
            ['six_digit' => '00123a'],
        ] as $i => $override) {
            $outcome = $this->publish($this->pastDate(10 + $i), '14:00', $override);

            $this->assertSame('rejected', $outcome['status'], 'Accepted malformed: '.json_encode($override));
        }

        $this->assertSame(0, PcsoLotteryResult::query()->count());
    }

    public function test_24_a_five_digit_search_is_invalid(): void
    {
        $search = app(PcsoLotterySearchService::class);

        foreach (['6d', '4d', '3d', '2d'] as $type) {
            $this->assertSame('INVALID_QUERY', $search->search($type, '12345')['status']);
        }
    }

    public function test_25_a_one_digit_search_is_invalid(): void
    {
        $search = app(PcsoLotterySearchService::class);

        foreach (['6d', '4d', '3d', '2d'] as $type) {
            $this->assertSame('INVALID_QUERY', $search->search($type, '9')['status']);
        }
    }

    public function test_26_exact_searches_work_at_all_four_widths(): void
    {
        $this->publish($this->pastDate(3), '14:00', [
            'six_digit' => '001234',
            'four_digit' => '0049',
            'three_digit' => '007',
            'two_digit' => '09',
        ]);

        $search = app(PcsoLotterySearchService::class);

        foreach ([['6d', '001234'], ['4d', '0049'], ['3d', '007'], ['2d', '09']] as [$type, $term]) {
            $this->assertSame('RESULT_FOUND', $search->search($type, $term)['status'], $type.' '.$term);
        }

        // A term stripped of its leading zero is a different value and must
        // not match.
        $this->assertSame('INVALID_QUERY', $search->search('4d', '49')['status']);
        $this->assertSame('INVALID_QUERY', $search->search('2d', '9')['status']);

        // The schema is what drives those widths.
        $schema = app(LaneSchemaFactory::class)->for('pcso_lottery');
        $this->assertSame([2, 3, 4, 6], $schema->searchableWidths());
    }

    // =====================================================================
    // 27-28  Several draws on one date
    // =====================================================================

    public function test_27_three_draws_on_one_date_coexist_and_order_by_time(): void
    {
        $date = $this->pastDate(3);

        // Imported OUT of chronological order on purpose: if ordering fell
        // back to id, the "latest" draw would be whichever was imported last.
        $this->publish($date, '17:00', ['six_digit' => '222222']);
        $this->publish($date, '21:00', ['six_digit' => '333333']);
        $this->publish($date, '14:00', ['six_digit' => '111111']);

        $this->assertSame(3, PcsoLotteryDraw::query()->where('draw_date', $date)->count());

        $references = PcsoLotteryDraw::query()
            ->where('draw_date', $date)
            ->orderBy('draw_time_local')
            ->pluck('draw_reference')
            ->all();

        $ymd = str_replace('-', '', $date);
        $this->assertSame([
            'PCSO-'.$ymd.'-1400',
            'PCSO-'.$ymd.'-1700',
            'PCSO-'.$ymd.'-2100',
        ], $references);

        // Current is the latest TIME, not the last insert.
        $current = app(PcsoLotteryResultService::class)->currentResult();
        $this->assertSame('333333', $current['numbers']['six_digit']);
        $this->assertSame('21:00', $current['draw']['time_local']);

        // History keeps all three as separate rows rather than grouping them.
        $history = app(PcsoLotteryHistoryService::class)
            ->historyForYear((int) substr($date, 0, 4), 1);

        $this->assertGreaterThanOrEqual(3, count($history['rows']));
    }

    public function test_28_an_exactly_duplicate_draw_identity_is_not_a_second_draw(): void
    {
        $date = $this->pastDate(3);

        $first = $this->publish($date, '14:00');
        $this->assertSame('imported', $first['status']);

        // Same date, same time expressed differently, same payload otherwise.
        // '2:00 PM' and '14:00' must normalise to one identity, or the lane
        // would grow a second draw every time a source changed its time
        // formatting.
        $again = $this->publish($date, '2:00 PM', [
            'source_identifier' => 'FIXTURE-'.$date.'-14:00',
        ]);

        $this->assertSame('duplicate', $again['status'], implode(',', $again['errors']));
        $this->assertSame($first['draw_reference'], $again['draw_reference']);
        $this->assertSame(1, PcsoLotteryDraw::query()->where('draw_date', $date)->count());

        // A different payload for the same identity is a conflict, never a
        // silent overwrite.
        $conflict = $this->publish($date, '14:00', ['six_digit' => '999999', 'source_identifier' => 'OTHER']);
        $this->assertSame('conflict', $conflict['status']);
    }

    // =====================================================================
    // 29-30  Nothing private, nothing copied
    // =====================================================================

    public function test_29_no_private_data_or_secret_reaches_a_public_surface(): void
    {
        config()->set('pcso_lottery.sources.official.endpoint', 'https://feed.example.invalid/pcso?key=topsecret');
        config()->set('pcso_lottery.sources.official.token', 'topsecret');

        $outcome = $this->publish($this->pastDate(3), '14:00', [], 'fixture');

        foreach ([
            $this->get('/pcso-lottery'),
            $this->get(route('pcso-lottery.show', ['draw' => $outcome['draw_reference']])),
        ] as $response) {
            $response->assertOk();

            foreach ([
                'topsecret', '@example.com', 'national_id', 'bank_account',
                'imported_by', 'SQLSTATE', '/home/', 'feed.example.invalid/pcso',
            ] as $needle) {
                $response->assertDontSee($needle, false);
            }
        }

        $this->assertStringNotContainsString('topsecret', PcsoLotteryResultVersion::query()->get()->toJson());
    }

    public function test_30_no_competitor_string_exists_in_the_shipped_lane(): void
    {
        $files = array_merge(
            glob(app_path('Services/Lottery/PcsoLottery*.php')) ?: [],
            glob(app_path('Models/PcsoLottery*.php')) ?: [],
            glob(app_path('Lottery/Schema/*.php')) ?: [],
            glob(resource_path('views/pcso-lottery/*.blade.php')) ?: [],
            glob(resource_path('views/components/pcso-lottery/*.blade.php')) ?: [],
            [
                config_path('pcso_lottery.php'),
                lang_path('en/pcso_lottery.php'),
                lang_path('th/pcso_lottery.php'),
                resource_path('css/pcso-lottery.css'),
                resource_path('js/pcso-lottery.js'),
                base_path('.env.example'),
                public_path('robots.txt'),
            ],
        );

        foreach ($files as $file) {
            $this->assertStringNotContainsStringIgnoringCase(
                'thailotto',
                (string) file_get_contents((string) $file),
                basename((string) $file).' must not reference a competitor',
            );
        }
    }

    // =====================================================================
    // 31  The lane states only what it can prove
    // =====================================================================

    public function test_31_integrity_is_hash_only_and_never_claims_a_signature(): void
    {
        $outcome = $this->publish($this->pastDate(3));

        $this->assertSame('INTEGRITY_HASH_ONLY', $outcome['integrity']['status']);
        $this->assertFalse($outcome['integrity']['native']);
        $this->assertSame('PCSO1', $outcome['integrity']['canonical_version']);

        $version = PcsoLotteryResultVersion::query()->firstOrFail();
        $this->assertSame('INTEGRITY_HASH_ONLY', $version->integrity_status);
        $this->assertFalse($version->integrity_native_verified);

        $this->get(route('pcso-lottery.show', ['draw' => $outcome['draw_reference']]))
            ->assertOk()
            ->assertDontSee('SIGNED_VERIFIED', false);
    }

    public function test_32_the_fingerprint_domain_is_lane_and_schema_specific(): void
    {
        // Two lanes publishing the same numbers on the same date must not
        // produce the same fingerprint, or a duplicate check keyed on it
        // would reject a legitimate import in the other lane.
        $date = $this->pastDate(3);

        $this->publish($date, '14:00', [
            'six_digit' => '001234',
            'four_digit' => '0049',
            'three_digit' => '007',
            'two_digit' => '09',
        ]);

        $pcso = PcsoLotteryResultVersion::query()->firstOrFail();

        $weekly = app(WeeklyLotteryImportService::class)->import([
            'draw_date' => $date,
            'first_6' => '001234',
            'three_ball' => '007',
            'two_ball' => '09',
            'source_identifier' => 'FIXTURE-'.$date.'-14:00',
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
        ], 'fixture');

        $this->assertSame('imported', $weekly['status'], implode(',', $weekly['errors']));

        $weeklyVersion = WeeklyLotteryResultVersion::query()->firstOrFail();

        $this->assertNotSame(
            $pcso->normalized_fingerprint,
            $weeklyVersion->normalized_fingerprint,
            'Two lanes produced the same fingerprint for the same numbers.',
        );
    }
}
