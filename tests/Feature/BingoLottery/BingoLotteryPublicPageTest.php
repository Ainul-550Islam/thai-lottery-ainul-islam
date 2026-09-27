<?php

declare(strict_types=1);

namespace Tests\Feature\BingoLottery;

use App\Enums\GloSourceState;
use App\Models\BingoLotteryDraw;
use App\Models\BingoLotteryResult;
use App\Models\BingoLotteryResultVersion;
use App\Services\Lottery\BingoLotteryDateService;
use App\Services\Lottery\BingoLotteryHistoryService;
use App\Services\Lottery\BingoLotteryImportService;
use App\Services\Lottery\BingoLotteryResultService;
use App\Services\Lottery\BingoLotterySearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Public Bingo / Mega Lottery result surface (PROMPT 8, file 30).
 *
 * NO NUMBER IN THIS FILE CAME FROM ANY COMPETITOR PAGE. Every fixture value is
 * synthetic and chosen to exercise a rule: 001234 and 004615 prove a six-digit
 * leading-zero round trip, 059696 and 074646 prove interior zeros, 049 / 014
 * prove the three-wide field, 09 and 00 prove the two-wide one and prove that
 * '00' is a real result rather than an absent one.
 *
 * FIXTURES ARE BUILT THROUGH THE REAL IMPORT SERVICE, not by inserting rows. A
 * test that hand-writes a published result proves nothing about the code that
 * publishes one; going through the importer means every assertion below also
 * exercises validation, fingerprinting, provenance and publication.
 *
 * The reference surface that motivated this lane is named only in the PROMPT
 * brief, never in shipped code. No markup, text, styling, value or claim from
 * it exists anywhere in this repository.
 */
final class BingoLotteryPublicPageTest extends TestCase
{
    use RefreshDatabase;

    private function importer(): BingoLotteryImportService
    {
        return app(BingoLotteryImportService::class);
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
            'first_6_mega' => '001234',
            'three_mega' => '049',
            'two_mega' => '09',
            'source_identifier' => 'FIXTURE-'.$isoDate,
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
        ], $overrides);

        return $this->importer()->import($payload, $provider);
    }

    private function pastDate(int $daysAgo): string
    {
        return app(BingoLotteryDateService::class)->today()->subDays($daysAgo)->format('Y-m-d');
    }

    // =====================================================================
    // 1-4  Every public route is anonymous
    // =====================================================================

    public function test_01_landing_page_is_public_without_login(): void
    {
        $response = $this->get('/bingo-lottery');

        $response->assertOk();
        $this->assertSame(200, $response->getStatusCode(), 'A login redirect would also not be 200.');
    }

    public function test_02_search_page_is_public_without_login(): void
    {
        $this->get(route('bingo-lottery.search', ['type' => '6mega', 'term' => '001234']))
            ->assertOk();
    }

    public function test_03_year_page_is_public_without_login(): void
    {
        $date = $this->pastDate(3);
        $this->publish($date);

        $this->get(route('bingo-lottery.year', ['year' => (int) substr($date, 0, 4)]))
            ->assertOk();
    }

    public function test_04_draw_detail_is_public_without_login(): void
    {
        $outcome = $this->publish($this->pastDate(3));

        $this->get(route('bingo-lottery.show', ['draw' => $outcome['draw_reference']]))
            ->assertOk();
    }

    // =====================================================================
    // 5-7  Navigation exposes all three lanes
    // =====================================================================

    public function test_05_guest_navigation_exposes_mega_lottery(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(route('bingo-lottery.index'), false);
        $response->assertSee('Mega Lottery', false);
    }

    public function test_06_guest_navigation_still_exposes_national_lottery(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(route('national-lottery.index'), false);
        $response->assertSee('National Lottery', false);
    }

    public function test_07_guest_navigation_still_exposes_weekly_lottery(): void
    {
        // A new lane must not displace an existing one.
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(route('weekly-lottery.index'), false);
        $response->assertSee('Weekly Lottery', false);
    }

    // =====================================================================
    // 8-10  robots and SEO
    // =====================================================================

    public function test_08_robots_blocks_only_the_mega_search_route(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Disallow: /bingo-lottery/search', $robots);

        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim((string) $line);

            if (! str_starts_with($line, 'Disallow:')) {
                continue;
            }

            $path = trim(substr($line, strlen('Disallow:')));

            if (str_starts_with($path, '/bingo-lottery')) {
                $this->assertSame('/bingo-lottery/search', $path, 'Only the search route may be blocked, got: '.$path);
            }
        }
    }

    public function test_09_robots_leaves_the_content_pages_crawlable(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertStringNotContainsString('Disallow: /bingo-lottery/year', $robots);
        $this->assertDoesNotMatchRegularExpression('/^Disallow:\s*\/bingo-lottery\/?\s*$/m', $robots);

        // And the sibling lanes are untouched.
        $this->assertStringContainsString('Disallow: /national-lottery/search', $robots);
        $this->assertStringContainsString('Disallow: /weekly-lottery/search', $robots);
    }

    public function test_10_the_search_page_is_noindex(): void
    {
        $response = $this->get(route('bingo-lottery.search', ['type' => '6mega', 'term' => '001234']));

        $response->assertOk();
        $response->assertSee('noindex,follow', false);
    }

    // =====================================================================
    // 11-12  The wildcard must not swallow its siblings
    // =====================================================================

    public function test_11_the_draw_wildcard_does_not_swallow_search(): void
    {
        $matched = app('router')->getRoutes()
            ->match(Request::create('/bingo-lottery/search', 'GET'))
            ->getName();

        $this->assertSame('bingo-lottery.search', $matched);
    }

    public function test_12_the_draw_wildcard_does_not_swallow_year(): void
    {
        $matched = app('router')->getRoutes()
            ->match(Request::create('/bingo-lottery/year/2026', 'GET'))
            ->getName();

        $this->assertSame('bingo-lottery.year', $matched);
    }

    // =====================================================================
    // 13-14  Configuration contract
    // =====================================================================

    public function test_13_every_config_env_key_is_documented_in_env_example(): void
    {
        $config = (string) file_get_contents(config_path('bingo_lottery.php'));
        $example = (string) file_get_contents(base_path('.env.example'));

        preg_match_all("/env\(\s*'(BINGO_LOTTERY_[A-Z0-9_]+)'/", $config, $matches);

        $keys = array_values(array_unique($matches[1]));
        $this->assertNotEmpty($keys, 'The config parser found no keys, so the parser is broken.');

        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression(
                '/^#?\s*'.preg_quote($key, '/').'=/m',
                $example,
                $key.' is read by config/bingo_lottery.php but is not documented in .env.example',
            );
        }
    }

    public function test_14_credentials_are_commented_out_and_empty(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        foreach (['BINGO_LOTTERY_OFFICIAL_ENDPOINT', 'BINGO_LOTTERY_OFFICIAL_TOKEN'] as $secret) {
            $this->assertMatchesRegularExpression('/^#\s*'.preg_quote($secret, '/').'=\s*$/m', $example);
            $this->assertDoesNotMatchRegularExpression('/^'.preg_quote($secret, '/').'=.+/m', $example);
        }

        // This lane has no verifier, so it must not DEFINE a key for one.
        //
        // The name may still appear in prose - the .env.example block explains
        // why these settings are absent, and deleting that explanation to
        // satisfy a substring check would remove the very thing that stops
        // someone adding them back. So the assertion is about assignments and
        // config reads, not about the characters appearing anywhere.
        $this->assertDoesNotMatchRegularExpression('/^#?\s*BINGO_LOTTERY_INTEGRITY[A-Z_]*=/m', $example);
        $this->assertDoesNotMatchRegularExpression(
            "/env\(\s*'BINGO_LOTTERY_INTEGRITY/",
            (string) file_get_contents(config_path('bingo_lottery.php')),
        );
    }

    // =====================================================================
    // 15  Fixture is never official
    // =====================================================================

    public function test_15_a_fixture_can_never_appear_as_official(): void
    {
        // Configure an endpoint - the condition under which a careless
        // implementation starts calling everything official.
        config()->set('bingo_lottery.sources.official.endpoint', 'https://example.invalid/feed');
        config()->set('bingo_lottery.sources.official.token', 'never-logged');

        $outcome = $this->publish($this->pastDate(3), [], 'fixture');

        $this->assertSame(GloSourceState::FixtureOnly->value, $outcome['source_state']);

        $response = $this->get('/bingo-lottery');
        $response->assertOk();
        $response->assertDontSee(GloSourceState::OfficialSourceVerified->value, false);
        $response->assertDontSee('never-logged', false);

        // Source priority is untouched and fixtures are not a fallback.
        $this->assertSame(['official', 'internal', 'replay', 'fixture'], config('bingo_lottery.sources.priority'));
        $this->assertFalse(config('bingo_lottery.sources.fall_through_to_fixture'));
    }

    // =====================================================================
    // 16-18  Leading zeros survive every layer
    // =====================================================================

    public function test_16_leading_zeros_survive_database_persistence(): void
    {
        foreach ([['004615', '049', '09'], ['059696', '014', '00'], ['074646', '000', '07']] as $i => [$six, $three, $two]) {
            $outcome = $this->publish($this->pastDate(3 + $i), [
                'first_6_mega' => $six,
                'three_mega' => $three,
                'two_mega' => $two,
            ]);

            $this->assertSame('imported', $outcome['status'], implode(',', $outcome['errors']));

            $draw = BingoLotteryDraw::query()->where('draw_reference', $outcome['draw_reference'])->firstOrFail();
            $row = $draw->currentResult()->first();

            $this->assertIsString($row->first_6_mega);
            $this->assertSame($six, $row->first_6_mega);
            $this->assertSame($three, $row->three_mega);
            $this->assertSame($two, $row->two_mega);
        }
    }

    public function test_17_leading_zeros_survive_api_serialization(): void
    {
        $outcome = $this->publish($this->pastDate(3), [
            'first_6_mega' => '004615',
            'three_mega' => '014',
            'two_mega' => '00',
        ]);

        $response = $this->getJson('/api/v1/bingo-lottery/results/'.$outcome['draw_reference']);

        $response->assertOk();

        $body = (string) $response->getContent();

        // Asserted against the RAW JSON: a value serialised as a number would
        // appear unquoted, and json_decode would hide that by handing back an
        // int that still equals the string in a loose comparison.
        $this->assertStringContainsString('"004615"', $body);
        $this->assertStringContainsString('"014"', $body);
        $this->assertStringContainsString('"00"', $body);
        $this->assertStringNotContainsString(':4615', $body);
    }

    public function test_18_leading_zeros_survive_blade_rendering(): void
    {
        $outcome = $this->publish($this->pastDate(3), [
            'first_6_mega' => '004615',
            'three_mega' => '014',
            'two_mega' => '09',
        ]);

        $this->get(route('bingo-lottery.show', ['draw' => $outcome['draw_reference']]))
            ->assertOk()
            ->assertSee('004615', false)
            ->assertSee('014', false)
            ->assertSee('09', false);
    }

    // =====================================================================
    // 19-20  Nothing private escapes
    // =====================================================================

    public function test_19_no_private_field_reaches_a_public_page(): void
    {
        $outcome = $this->publish($this->pastDate(3));

        foreach ([
            $this->get('/bingo-lottery'),
            $this->get(route('bingo-lottery.show', ['draw' => $outcome['draw_reference']])),
        ] as $response) {
            $response->assertOk();

            foreach (['@example.com', 'national_id', 'bank_account', 'imported_by', 'SQLSTATE'] as $needle) {
                $response->assertDontSee($needle, false);
            }
        }
    }

    public function test_20_no_secret_or_internal_path_is_leaked(): void
    {
        config()->set('bingo_lottery.sources.official.endpoint', 'https://feed.example.invalid/mega?key=topsecret');
        config()->set('bingo_lottery.sources.official.token', 'topsecret');

        $outcome = $this->publish($this->pastDate(3), [], 'fixture');

        $response = $this->get(route('bingo-lottery.show', ['draw' => $outcome['draw_reference']]));
        $response->assertOk();

        $response->assertDontSee('topsecret', false);
        $response->assertDontSee('/home/', false);
        $response->assertDontSee('feed.example.invalid/mega', false);

        // Nothing persisted the credential either.
        $versions = BingoLotteryResultVersion::query()->get()->toJson();
        $this->assertStringNotContainsString('topsecret', $versions);
    }

    // =====================================================================
    // 21  Malformed input is refused, never repaired
    // =====================================================================

    public function test_21_a_malformed_result_length_is_rejected_not_padded(): void
    {
        foreach ([
            ['first_6_mega' => '1234'],
            ['first_6_mega' => '0012345'],
            ['three_mega' => '49'],
            ['two_mega' => '9'],
            ['first_6_mega' => '00123a'],
        ] as $i => $override) {
            $outcome = $this->publish($this->pastDate(10 + $i), $override);

            $this->assertSame('rejected', $outcome['status'], 'Accepted a malformed value: '.json_encode($override));
        }

        // Nothing was written by any of those attempts.
        $this->assertSame(0, BingoLotteryResult::query()->count());
    }

    // =====================================================================
    // 22-24  Bounds
    // =====================================================================

    public function test_22_the_search_limiter_is_configured_and_enforced(): void
    {
        $this->assertGreaterThan(0, (int) config('bingo_lottery.rate_limit.per_minute'));
        $this->assertGreaterThan(0, (int) config('bingo_lottery.rate_limit.per_hour'));
        $this->assertGreaterThan(0, (int) config('bingo_lottery.rate_limit.fingerprint_per_minute'));

        // The route really carries the limiter; a configured ceiling nobody
        // applies is not a control.
        $route = app('router')->getRoutes()->match(Request::create('/bingo-lottery/search', 'GET'));

        $this->assertContains('throttle:bingo-result-search', $route->gatherMiddleware());
    }

    public function test_23_the_search_term_is_bounded(): void
    {
        $max = (int) config('bingo_lottery.page.max_search_length');

        $this->assertGreaterThan(0, $max);

        $response = $this->get(route('bingo-lottery.search', [
            'type' => '6mega',
            'term' => str_repeat('1', $max + 500),
        ]));

        // Refused by validation, never passed to a query.
        $this->assertContains($response->getStatusCode(), [302, 422]);
    }

    public function test_24_the_history_year_is_bounded(): void
    {
        $min = (int) config('bingo_lottery.calendar.min_gregorian_year');

        $this->assertGreaterThan(0, $min);

        $response = $this->get('/bingo-lottery/year/1000');

        // A year below the floor cannot reach an unbounded scan; the page
        // either refuses it or renders an honest empty archive.
        $this->assertContains($response->getStatusCode(), [200, 302, 404, 422]);

        if ($response->getStatusCode() === 200) {
            $response->assertDontSee('001234', false);
        }
    }

    // =====================================================================
    // 25  No competitor string in anything this lane touched
    // =====================================================================

    public function test_25_no_competitor_string_exists_in_the_shipped_lane(): void
    {
        $files = array_merge(
            glob(app_path('Services/Lottery/BingoLottery*.php')) ?: [],
            glob(app_path('Models/BingoLottery*.php')) ?: [],
            glob(resource_path('views/bingo-lottery/*.blade.php')) ?: [],
            glob(resource_path('views/components/bingo-lottery/*.blade.php')) ?: [],
            [
                config_path('bingo_lottery.php'),
                lang_path('en/bingo_lottery.php'),
                lang_path('th/bingo_lottery.php'),
                resource_path('css/bingo-lottery.css'),
                resource_path('js/bingo-lottery.js'),
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
    // 26-30  Lane behaviour the seams depend on
    // =====================================================================

    public function test_26_current_result_is_the_latest_draw_date_not_the_highest_id(): void
    {
        // Newest DATE imported FIRST, so the highest id belongs to the OLDER
        // draw. MAX(id) would answer wrongly here.
        $this->publish($this->pastDate(1), ['first_6_mega' => '111111']);
        $this->publish($this->pastDate(30), ['first_6_mega' => '222222']);

        $current = app(BingoLotteryResultService::class)->currentResult();

        $this->assertSame('111111', $current['numbers']['first_6_mega']);
    }

    public function test_27_an_unavailable_draw_is_never_rendered_as_zeros(): void
    {
        $outcome = $this->publish($this->pastDate(3), [
            'first_6_mega' => null,
            'three_mega' => null,
            'two_mega' => null,
        ]);

        $this->assertSame('imported', $outcome['status'], implode(',', $outcome['errors']));
        $this->assertSame(BingoLotteryDraw::RESULT_UNAVAILABLE, $outcome['result_status']);

        $draw = BingoLotteryDraw::query()->where('draw_reference', $outcome['draw_reference'])->firstOrFail();
        $row = $draw->currentResult()->first();

        $this->assertNull($row->first_6_mega);
        $this->assertNull($row->three_mega);
        $this->assertNull($row->two_mega);

        $this->get(route('bingo-lottery.show', ['draw' => $outcome['draw_reference']]))
            ->assertOk()
            ->assertDontSee('000000', false)
            ->assertDontSee('>000<', false);
    }

    public function test_28_years_come_from_stored_draws_not_a_hardcoded_list(): void
    {
        $this->assertSame([], app(BingoLotteryHistoryService::class)->availableYears());

        $date = $this->pastDate(3);
        $this->publish($date);

        $years = app(BingoLotteryHistoryService::class)->availableYears();

        $this->assertCount(1, $years);
        $this->assertSame((int) substr($date, 0, 4), $years[0]['gregorian']);
        // Buddhist era is computed by the date service, never hardcoded.
        $this->assertSame($years[0]['gregorian'] + 543, $years[0]['buddhist']);
    }

    public function test_29_search_requires_an_explicit_type_and_matches_exactly(): void
    {
        $this->publish($this->pastDate(3), ['first_6_mega' => '004615', 'two_mega' => '00']);

        $search = app(BingoLotterySearchService::class);

        $found = $search->search('6mega', '004615');
        $this->assertSame('RESULT_FOUND', $found['status']);

        // '00' is a real two-wide result, not an absent one.
        $this->assertSame('RESULT_FOUND', $search->search('2mega', '00')['status']);

        // A shortened term is not silently widened into a match.
        $this->assertSame('INVALID_QUERY', $search->search('6mega', '4615')['status']);
        $this->assertSame('INVALID_QUERY', $search->search('2mega', '0')['status']);

        // A type outside the closed list can never reach a query.
        $this->assertSame('INVALID_QUERY', $search->search('first_6_mega', '004615')['status']);
    }

    public function test_30_the_lane_reports_hash_only_integrity_and_never_claims_a_signature(): void
    {
        $outcome = $this->publish($this->pastDate(3));

        $this->assertSame('INTEGRITY_HASH_ONLY', $outcome['integrity']['status']);
        $this->assertFalse($outcome['integrity']['native']);
        $this->assertSame('MEGA1', $outcome['integrity']['canonical_version']);

        $version = BingoLotteryResultVersion::query()->firstOrFail();
        $this->assertSame('INTEGRITY_HASH_ONLY', $version->integrity_status);
        $this->assertFalse($version->integrity_native_verified);

        // The page may not render a claim the lane cannot support.
        $response = $this->get(route('bingo-lottery.show', ['draw' => $outcome['draw_reference']]));
        $response->assertOk();
        $response->assertDontSee('SIGNED_VERIFIED', false);
        $response->assertDontSee(trans('bingo_lottery.integrity_status.integrity_hash_only').' and signed', false);

        // Supplying signature material is refused, not ignored.
        $signed = $this->importer()->import([
            'draw_date' => $this->pastDate(9),
            'first_6_mega' => '001234',
            'three_mega' => '049',
            'two_mega' => '09',
            'source_identifier' => 'FIXTURE-SIGNED',
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
            // A well-formed Ed25519 signature: 128 hex characters. The point
            // is that it is SYNTACTICALLY valid, so the refusal comes from the
            // lane having no verifier rather than from failed parsing.
            'signature_hex' => str_repeat('ab', 64),
        ], 'fixture');

        $this->assertSame('rejected', $signed['status']);
        $this->assertSame('SIGNATURE_UNSUPPORTED', $signed['integrity']['status']);
    }
}
