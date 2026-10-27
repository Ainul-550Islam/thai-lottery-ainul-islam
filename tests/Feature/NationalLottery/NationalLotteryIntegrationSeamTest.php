<?php

declare(strict_types=1);

namespace Tests\Feature\NationalLottery;

use App\Enums\GloSourceState;
use App\Models\NationalLotteryDraw;
use App\Services\Lottery\NationalLotteryDateService;
use App\Services\Lottery\NationalLotteryImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * National Lottery integration seam (PROMPT 7, file 30).
 *
 * The lane itself was finished and tested in PROMPT 5. What was missing was
 * every connection between the lane and the rest of the repository: the page
 * was routed but linked from nothing, the configuration was read but
 * documented nowhere, and the enumerable search route was left open to
 * crawlers. None of those omissions is visible in a per-file review, because
 * no single file is missing - the gap lives in the seam between files.
 *
 * This suite exists so the seam cannot quietly reopen. It asserts the
 * agreement between config, .env.example, robots.txt, the guest navigation,
 * the route table and the public pages.
 *
 * NO NUMBER IN THIS FILE CAME FROM ANY COMPETITOR PAGE. 004615, 059696 and
 * 074646 are synthetic six-digit values chosen because each carries a leading
 * or interior zero that a numeric cast would destroy.
 */
final class NationalLotteryIntegrationSeamTest extends TestCase
{
    use RefreshDatabase;

    private function importer(): NationalLotteryImportService
    {
        return app(NationalLotteryImportService::class);
    }

    /**
     * Publish one draw through the real import path, exactly as the existing
     * public-page suite does. A hand-inserted row would prove nothing about
     * the code that actually publishes a result.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function publish(string $isoDate, array $overrides = [], string $provider = 'fixture'): array
    {
        $payload = array_merge([
            'draw_date' => $isoDate,
            'first_prize' => '004615',
            'three_up' => '007',
            'two_up' => '04',
            'two_down' => '09',
            'three_front' => ['010', '020'],
            'three_after' => ['030', '040'],
            'source_identifier' => 'SEAM-'.$isoDate,
            'retrieved_at' => '2026-01-01T00:00:00+00:00',
        ], $overrides);

        return $this->importer()->import($payload, $provider);
    }

    private function pastDate(int $daysAgo): string
    {
        return app(NationalLotteryDateService::class)->today()->subDays($daysAgo)->format('Y-m-d');
    }

    private function robots(): string
    {
        return (string) file_get_contents(public_path('robots.txt'));
    }

    private function envExample(): string
    {
        return (string) file_get_contents(base_path('.env.example'));
    }

    /**
     * Just the National Lottery block of .env.example.
     *
     * The file documents many unrelated subsystems; assertions about what
     * this lane claims have to be scoped to what this lane wrote.
     */
    private function nationalEnvBlock(): string
    {
        $example = $this->envExample();
        $start = strpos($example, '# NATIONAL LOTTERY result lane');

        $this->assertIsInt($start, 'The National Lottery block is missing from .env.example.');

        $rest = substr($example, (int) $start);
        $next = strpos($rest, "\n###############################################################################\n# ", 1);

        return $next === false ? $rest : substr($rest, 0, (int) $next);
    }

    /**
     * Every NATIONAL_LOTTERY_* key the config actually reads, parsed from the
     * config source rather than listed here.
     *
     * A hard-coded list would pass forever while the config grew past it,
     * which is the exact failure this suite is meant to prevent.
     *
     * @return list<string>
     */
    private function configuredEnvKeys(): array
    {
        $config = (string) file_get_contents(config_path('national_lottery.php'));

        preg_match_all("/env\(\s*'(NATIONAL_LOTTERY_[A-Z0-9_]+)'/", $config, $matches);

        $keys = array_values(array_unique($matches[1]));
        sort($keys);

        return $keys;
    }

    // =====================================================================
    // 1-2  Guest navigation exposes both lanes
    // =====================================================================

    public function test_01_national_lottery_is_visible_in_guest_navigation(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(route('national-lottery.index'), false);
        $response->assertSee('National Lottery', false);
    }

    public function test_02_weekly_lottery_is_still_visible_in_guest_navigation(): void
    {
        // Adding one lane must not displace the other. Both are public
        // products and both have to stay discoverable.
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(route('weekly-lottery.index'), false);
        $response->assertSee('Weekly Lottery', false);
    }

    // =====================================================================
    // 3-6  Every public route reachable anonymously
    // =====================================================================

    public function test_03_landing_route_is_public(): void
    {
        $response = $this->get(route('national-lottery.index'));

        $response->assertOk();
        // Not a login redirect dressed up as success.
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_04_search_route_is_public(): void
    {
        $this->get(route('national-lottery.search', ['number' => '004615']))
            ->assertOk();
    }

    public function test_05_year_route_is_public(): void
    {
        $date = $this->pastDate(3);
        $this->publish($date);

        $year = (int) substr($date, 0, 4);

        $this->get(route('national-lottery.year', ['year' => $year]))
            ->assertOk();
    }

    public function test_06_draw_detail_route_is_public(): void
    {
        $outcome = $this->publish($this->pastDate(3));

        $this->get(route('national-lottery.show', ['draw' => $outcome['draw_reference']]))
            ->assertOk();
    }

    // =====================================================================
    // 7-9  robots.txt blocks the search space and nothing else
    // =====================================================================

    public function test_07_robots_blocks_the_national_search_route(): void
    {
        $this->assertStringContainsString('Disallow: /national-lottery/search', $this->robots());
    }

    public function test_08_robots_does_not_block_the_national_result_pages(): void
    {
        $robots = $this->robots();

        // A bare "Disallow: /national-lottery" would prefix-match the whole
        // lane, including the landing page, and silently deindex the content
        // this product exists to publish.
        foreach ($robots === '' ? [] : preg_split('/\R/', $robots) as $line) {
            $line = trim((string) $line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! str_starts_with($line, 'Disallow:')) {
                continue;
            }

            $path = trim(substr($line, strlen('Disallow:')));

            if (! str_starts_with($path, '/national-lottery')) {
                continue;
            }

            $this->assertSame(
                '/national-lottery/search',
                $path,
                'The only National Lottery path robots.txt may block is the search route, got: '.$path,
            );
        }

        $this->assertStringNotContainsString('Disallow: /national-lottery/year', $robots);
    }

    public function test_09_robots_does_not_block_the_other_public_surfaces(): void
    {
        $robots = $this->robots();

        foreach (['/results', '/weekly-lottery', '/ticket-check', '/sales-points'] as $path) {
            $this->assertDoesNotMatchRegularExpression(
                '/^Disallow:\s*'.preg_quote($path, '/').'\s*$/m',
                $robots,
                $path.' must stay crawlable',
            );
        }

        // The pre-existing rules survive.
        $this->assertStringContainsString('Disallow: /admin', $robots);
        $this->assertStringContainsString('Disallow: /api', $robots);
    }

    // =====================================================================
    // 10-11  Configuration is documented, credentials are not
    // =====================================================================

    public function test_10_every_configured_env_key_is_documented_in_env_example(): void
    {
        $keys = $this->configuredEnvKeys();
        $example = $this->envExample();

        $this->assertNotEmpty($keys, 'The config parser found no keys, which means the parser is broken.');

        foreach ($keys as $key) {
            // A commented-out entry counts as documented: a credential must be
            // named so an operator knows it exists, but must ship valueless.
            $this->assertMatchesRegularExpression(
                '/^#?\s*'.preg_quote($key, '/').'=/m',
                $example,
                $key.' is read by config/national_lottery.php but is not documented in .env.example',
            );
        }
    }

    public function test_11_credentials_are_commented_out_and_empty_in_env_example(): void
    {
        $example = $this->envExample();

        foreach ([
            'NATIONAL_LOTTERY_OFFICIAL_ENDPOINT',
            'NATIONAL_LOTTERY_OFFICIAL_TOKEN',
        ] as $secret) {
            // Named, so it is discoverable.
            $this->assertMatchesRegularExpression('/^#\s*'.preg_quote($secret, '/').'=\s*$/m', $example);
            // Never live with a value.
            $this->assertDoesNotMatchRegularExpression('/^'.preg_quote($secret, '/').'=.+/m', $example);
        }

        // This lane has no integrity verifier, so it must not advertise a key
        // for one. The Rust verifier belongs to the Weekly lane.
        $this->assertStringNotContainsString('NATIONAL_LOTTERY_INTEGRITY_PUBLIC_KEY=', $example);
        $this->assertStringNotContainsString(
            'NATIONAL_LOTTERY_INTEGRITY',
            (string) file_get_contents(config_path('national_lottery.php')),
        );
    }

    // =====================================================================
    // 12  Fixture data is never dressed as official
    // =====================================================================

    public function test_12_a_fixture_import_is_never_labelled_official(): void
    {
        // Configure an official endpoint, which is the condition under which a
        // careless implementation would start calling everything official.
        config()->set('national_lottery.sources.official.endpoint', 'https://example.invalid/feed');
        config()->set('national_lottery.sources.official.token', 'never-logged');

        $outcome = $this->publish($this->pastDate(3), [], 'fixture');

        $this->assertSame(GloSourceState::FixtureOnly->value, $outcome['source_state']);
        $this->assertNotSame(GloSourceState::OfficialSourceVerified->value, $outcome['source_state']);

        $response = $this->get(route('national-lottery.index'));
        $response->assertOk();
        $response->assertDontSee(GloSourceState::OfficialSourceVerified->value, false);
        // The token never leaves the server.
        $response->assertDontSee('never-logged', false);

        // Source priority is untouched by the seam work.
        $this->assertSame(['official', 'internal', 'fixture'], config('national_lottery.sources.priority'));
        $this->assertFalse(config('national_lottery.sources.fall_through_to_fixture'));
    }

    // =====================================================================
    // 13  Search stays out of the index
    // =====================================================================

    public function test_13_the_search_page_is_still_noindex(): void
    {
        $response = $this->get(route('national-lottery.search', ['number' => '004615']));

        $response->assertOk();
        $response->assertSee('noindex,follow', false);

        // Adding a robots.txt rule must not have flipped the page-level policy
        // the other way.
        $response->assertDontSee('<meta name="robots" content="index,follow">', false);
    }

    // =====================================================================
    // 14  Route order: the wildcard must not swallow its siblings
    // =====================================================================

    public function test_14_search_and_year_are_registered_before_the_draw_wildcard(): void
    {
        $paths = [];

        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();

            if (str_starts_with($uri, 'national-lottery')) {
                $paths[] = $uri;
            }
        }

        $wildcard = array_search('national-lottery/{draw}', $paths, true);
        $search = array_search('national-lottery/search', $paths, true);
        $year = array_search('national-lottery/year/{year}', $paths, true);

        $this->assertIsInt($wildcard, 'The draw detail route is missing.');
        $this->assertIsInt($search, 'The search route is missing.');
        $this->assertIsInt($year, 'The year route is missing.');

        $this->assertLessThan($wildcard, $search, 'The {draw} wildcard would swallow /search.');
        $this->assertLessThan($wildcard, $year, 'The {draw} wildcard would swallow /year.');

        // And behaviourally: /search resolves to the search action, not to a
        // draw lookup for a draw literally named "search".
        $this->assertSame(
            'national-lottery.search',
            app('router')->getRoutes()->match(
                Request::create('/national-lottery/search', 'GET')
            )->getName(),
        );
    }

    // =====================================================================
    // 15-16  Honesty of the shipped surface
    // =====================================================================

    public function test_15_no_competitor_string_reaches_the_public_page(): void
    {
        $response = $this->get(route('national-lottery.index'));

        $response->assertOk();
        $response->assertDontSee('thailotto', false);

        foreach ([
            base_path('.env.example'),
            public_path('robots.txt'),
            resource_path('views/layouts/app.blade.php'),
        ] as $file) {
            $this->assertStringNotContainsStringIgnoringCase(
                'thailotto',
                (string) file_get_contents($file),
                basename($file).' must not reference a competitor',
            );
        }
    }

    public function test_16_nothing_added_here_claims_a_government_relationship(): void
    {
        // Scoped to the National lane page and to the files PROMPT 7 touched.
        //
        // It is deliberately NOT asserted against the whole site: the GLO lane
        // legitimately names the Government Lottery Office, because that lane
        // really is about GLO draws. Naming an organisation is not the same as
        // claiming its endorsement, and a test that forbade the name outright
        // would be asserting something false about correct existing content.
        $response = $this->get(route('national-lottery.index'));
        $response->assertOk();

        // WHY THIS NO LONGER BANS THE PHRASE OUTRIGHT.
        //
        // The shared public footer now carries the platform's disclaimer:
        // "this site is not the official GLO website and is not a government
        // portal." That sentence necessarily CONTAINS the words it denies.
        // Banning the characters would have forced the page to drop its own
        // disclaimer to stay green - the test would have been removing the
        // honest statement it exists to protect.
        //
        // So the assertion is now stronger, not weaker: no AFFIRMATIVE claim
        // of official status may appear, and the denial MUST.
        foreach ([
            'is the official GLO',
            'Official Government',
            'government-approved',
            'officially licensed',
            'official government support',
        ] as $claim) {
            $response->assertDontSee($claim, false);
        }

        $response->assertSee('not the official GLO website', false);

        // Only the block PROMPT 7 added is checked for the organisation's
        // name. The file already contains a GLO section that names the
        // Government Lottery Office, which is accurate: that lane really does
        // cover GLO products.
        foreach ([
            'Government Lottery Office',
            'government-approved',
            'officially licensed',
        ] as $claim) {
            $this->assertStringNotContainsString($claim, $this->nationalEnvBlock());
        }

        foreach ([public_path('robots.txt'), base_path('.github/workflows/ci.yml')] as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString('government-approved', $contents);
            $this->assertStringNotContainsString('officially licensed', $contents);
        }

        // The lane may only describe an official SOURCE lane, never an
        // official STATUS it has not earned.
        $this->assertStringNotContainsString(
            'OFFICIAL_SOURCE_VERIFIED',
            $response->getContent() ?: '',
        );
    }

    // =====================================================================
    // 17-18  The lane's own guarantees still hold
    // =====================================================================

    public function test_17_leading_zeros_survive_the_seam_changes(): void
    {
        foreach (['004615', '059696', '074646'] as $index => $value) {
            $outcome = $this->publish($this->pastDate(3 + $index), ['first_prize' => $value]);

            $this->assertSame('imported', $outcome['status']);

            $draw = NationalLotteryDraw::query()
                ->where('draw_reference', $outcome['draw_reference'])
                ->firstOrFail();

            $stored = $draw->currentResult()->first();

            $this->assertIsString($stored->first_prize);
            $this->assertSame($value, $stored->first_prize);

            $this->get(route('national-lottery.show', ['draw' => $outcome['draw_reference']]))
                ->assertOk()
                ->assertSee($value, false);
        }
    }

    public function test_18_the_source_status_still_reports_the_real_state(): void
    {
        $outcome = $this->publish($this->pastDate(3), [], 'fixture');

        $this->assertSame(GloSourceState::FixtureOnly->value, $outcome['source_state']);

        $response = $this->get(route('national-lottery.show', ['draw' => $outcome['draw_reference']]));

        $response->assertOk();
        $response->assertSee(
            trans('national_lottery.source_state.'.strtolower(GloSourceState::FixtureOnly->value)),
            false,
        );
    }

    // =====================================================================
    // 19-20  Nothing private leaks through the new surfaces
    // =====================================================================

    public function test_19_no_personal_data_appears_on_the_public_surfaces(): void
    {
        $outcome = $this->publish($this->pastDate(3));

        foreach ([
            $this->get('/'),
            $this->get(route('national-lottery.index')),
            $this->get(route('national-lottery.show', ['draw' => $outcome['draw_reference']])),
        ] as $response) {
            $response->assertOk();

            foreach (['@example.com', 'national_id', 'bank_account', 'id_number'] as $needle) {
                $response->assertDontSee($needle, false);
            }
        }
    }

    public function test_20_no_secret_or_internal_path_appears_in_the_shipped_files(): void
    {
        $example = $this->envExample();

        // A real key would be long and high entropy; the example ships none.
        $this->assertDoesNotMatchRegularExpression('/^NATIONAL_LOTTERY_[A-Z_]*(TOKEN|KEY|SECRET)=\S+/m', $example);

        // And no absolute developer path escaped into the committed files.
        foreach ([$example, $this->robots(), (string) file_get_contents(resource_path('views/layouts/app.blade.php'))] as $contents) {
            $this->assertStringNotContainsString('/home/', $contents);
            $this->assertStringNotContainsString('/Users/', $contents);
        }
    }
}
