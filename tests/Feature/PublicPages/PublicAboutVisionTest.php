<?php

declare(strict_types=1);

namespace Tests\Feature\PublicPages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * PROMPT 2 — PublicAboutVisionTest (18 assertions groups):
 * /about and /vision are anonymous, uniquely titled, neutrally worded,
 * reflect the real Choose→Buy→Result→Claim flow, carry no fake founding
 * date / government relationship / competitor CLEAR framework, list only
 * proven governance controls, and keep en/th key parity with no banned copy.
 */
final class PublicAboutVisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // 1
    public function test_about_is_public_without_login(): void
    {
        $this->get('/about')->assertOk();
    }

    // 2
    public function test_vision_is_public_without_login(): void
    {
        $this->get('/vision')->assertOk();
    }

    // 3
    public function test_about_has_unique_title_and_meta_description(): void
    {
        $content = (string) $this->get('/about')->assertOk()->getContent();
        $this->assertStringContainsString('<title>', $content);
        $this->assertStringContainsString('About', $content);
        $this->assertMatchesRegularExpression(
            '/<meta name="description" content="[^"]{40,}"/',
            $content,
        );
    }

    // 4
    public function test_vision_has_unique_title_and_meta_description(): void
    {
        $content = (string) $this->get('/vision')->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<title>[^<]*Vision[^<]*<\/title>/',
            $content,
        );
        $this->assertMatchesRegularExpression(
            '/<meta name="description" content="[^"]{40,}"/',
            $content,
        );
    }

    // 5
    public function test_about_and_vision_metadata_are_distinct(): void
    {
        $about = (string) $this->get('/about')->assertOk()->getContent();
        $vision = (string) $this->get('/vision')->assertOk()->getContent();

        preg_match('/<meta name="description" content="([^"]+)"/', $about, $m1);
        preg_match('/<meta name="description" content="([^"]+)"/', $vision, $m2);
        $this->assertNotEmpty($m1[1] ?? '');
        $this->assertNotEmpty($m2[1] ?? '');
        $this->assertNotSame($m1[1], $m2[1]);
    }

    // 6
    public function test_canonical_links_present_and_absolute(): void
    {
        foreach (['/about' => 'about', '/vision' => 'vision'] as $path => $slug) {
            $content = (string) $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString(
                '<link rel="canonical" href="'.config('app.url').'/'.$slug.'"',
                $content,
            );
        }
    }

    // 7
    public function test_metadata_never_claims_official_glo(): void
    {
        foreach (['/about', '/vision'] as $path) {
            $content = (string) $this->get($path)->assertOk()->getContent();
            $headEnd = (int) strpos($content, '</head>');
            $this->assertGreaterThan(0, $headEnd);
            $head = substr($content, 0, $headEnd + 7);
            // SEO metadata only — body may say "not the official GLO website".
            $this->assertStringNotContainsStringIgnoringCase('official GLO', $head);
            $this->assertDoesNotMatchRegularExpression(
                '/(name|property)="(description|og:title|og:description)"[^>]*official\s+GLO/i',
                $content,
            );
        }
    }

    // 8
    public function test_how_it_works_reflects_choose_buy_result_claim(): void
    {
        $content = (string) $this->get('/about')->assertOk()->getContent();
        $this->assertStringContainsString('data-pp-step="choose"', $content);
        $this->assertStringContainsString('data-pp-step="buy"', $content);
        $this->assertStringContainsString('data-pp-step="result"', $content);
        $this->assertStringContainsString('data-pp-step="claim"', $content);
    }

    // 9
    public function test_about_has_no_fake_founding_date(): void
    {
        $content = (string) $this->get('/about')->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression(
            '/(founded|established|since|est\.)\s*\.?\s*(19|20)\d{2}/i',
            $content,
        );
        $this->assertStringNotContainsString('Founded in', $content);
        $this->assertStringNotContainsString('established in', $content);
    }

    // 10
    public function test_about_makes_no_government_relationship_claims(): void
    {
        $content = (string) $this->get('/about')->assertOk()->getContent();
        foreach ([
            'official agent portal',
            'operated by the Government',
            'owned by the Government',
            'endorsed by GLO',
            'certified by the government',
            'government-operated website',
        ] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $content);
        }
        // Neutral independence wording must be present (negated disclaimers allowed).
        $this->assertStringContainsString('not the Government Lottery Office', $content);
        $this->assertStringContainsString('not a government portal', $content);
        $this->assertStringContainsString('not an official GLO agent', $content);
    }

    // 11
    public function test_useful_links_point_at_existing_routes(): void
    {
        $content = (string) $this->get('/about')->assertOk()->getContent();
        $this->assertStringContainsString(route('results.index'), $content);
        $this->assertStringContainsString(route('ticket-check'), $content);
        $this->assertStringContainsString(route('contact'), $content);
        // No competitor or raw API primary links.
        $this->assertStringNotContainsString('thailotto.club', $content);
        $this->assertStringNotContainsString('https://api.', $content);
    }

    // 12
    public function test_contact_section_present(): void
    {
        $content = (string) $this->get('/about')->assertOk()->getContent();
        $this->assertStringContainsString('data-pp-section="contact"', $content);
        $this->assertStringContainsString(route('contact'), $content);
    }

    // 13
    public function test_vision_and_mission_sections_present(): void
    {
        $content = (string) $this->get('/vision')->assertOk()->getContent();
        $this->assertStringContainsString('data-pp-section="vision-mission"', $content);
        $this->assertStringContainsString('data-pp-section="core-values"', $content);
    }

    // 14
    public function test_core_values_are_our_own_framework_not_competitor_clear(): void
    {
        $content = (string) $this->get('/vision')->assertOk()->getContent();
        foreach ([
            'transparency',
            'security',
            'accountability',
            'fairness',
            'privacy',
            'reliability',
        ] as $value) {
            $this->assertStringContainsString('data-pp-value="'.$value.'"', $content);
        }
        // Competitor's CLEAR acronym framework must not be copied as a set.
        //
        // Previously this scanned the entire response, where the only match was
        // the word "Legal" in the site footer's "Legal & Support" column - a
        // heading on every page that has nothing to do with a values framework.
        // The claim is about the values this page publishes, so the search is
        // scoped to the values block that carries them.
        preg_match_all(
            '/data-pp-value="[^"]*"[^>]*>(.*?)<\/[a-z]+>/is',
            $content,
            $valueBlocks,
        );
        $valuesText = implode(' ', $valueBlocks[1]);

        $clearHits = 0;
        foreach (['Consistency', 'Legal', 'Efficiency', 'Accountability framework'] as $word) {
            if (str_contains($valuesText, $word)) {
                $clearHits++;
            }
        }
        $this->assertSame(0, $clearHits, 'no competitor CLEAR framework copy');
    }

    // 15
    public function test_governance_lists_only_proven_controls_without_absolute_security_claims(): void
    {
        $content = (string) $this->get('/vision')->assertOk()->getContent();
        $this->assertStringContainsString('data-pp-section="governance"', $content);
        foreach ([
            'idempotency',
            'transactional_ledger',
            'immutable_audit',
            'kyc_gates',
            'payout_holds',
        ] as $control) {
            $this->assertStringContainsString('data-pp-control="'.$control.'"', $content);
        }
        $this->assertStringNotContainsString('100% secure', $content);
        $this->assertStringNotContainsString('100% security', $content);
        $this->assertStringNotContainsString('unhackable', $content);
        $this->assertStringNotContainsString('zero-loss', $content);
        // Absolute-safety claims banned; honest limitation disclaimer required.
        $this->assertStringContainsString('not a promise of absolute safety', $content);
    }

    // 16
    public function test_language_files_have_identical_key_counts_and_keys(): void
    {
        $en = (array) require base_path('lang/en/public_pages.php');
        $th = (array) require base_path('lang/th/public_pages.php');
        $this->assertGreaterThan(80, count($en));
        $this->assertSame(array_keys($en), array_keys($th));
        $this->assertSame(count($en), count($th));
        // No visible missing keys: every en value non-empty.
        foreach ($en as $key => $value) {
            $this->assertIsString($value, (string) $key);
            $this->assertNotSame('', trim($value), 'empty en value for '.$key);
        }
        foreach ($th as $key => $value) {
            $this->assertNotSame('', trim((string) $value), 'empty th value for '.$key);
        }
    }

    // 17
    public function test_banned_competitor_and_stale_economics_absent(): void
    {
        foreach (['/about', '/vision'] as $path) {
            $content = (string) $this->get($path)->assertOk()->getContent();
            $this->assertStringNotContainsString('thailotto.club', $content);
            $this->assertStringNotContainsString('Thailotto', $content);
            $this->assertStringNotContainsString('40.00', $content);
            $this->assertStringNotContainsString('3,000,000', $content);
            $this->assertStringNotContainsString('3000000', $content);
        }
    }

    // 18
    public function test_accessibility_landmarks_keyboard_and_reduced_motion(): void
    {
        foreach (['/about', '/vision'] as $path) {
            $content = (string) $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('<main', $content);
            $this->assertStringContainsString('role="contentinfo"', $content);
            $this->assertStringContainsString('role="banner"', $content);
            $this->assertStringContainsString('aria-labelledby', $content);
            $this->assertStringContainsString('pp-skip-link', $content);
            $this->assertStringContainsString('lang="en"', $content);
            $this->assertStringContainsString('<h1', $content);
        }
        $css = (string) file_get_contents(base_path('resources/css/public-pages.css'));
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString(':focus-visible', $css);
    }
}
