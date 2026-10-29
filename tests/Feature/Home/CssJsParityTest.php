<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use Tests\TestCase;

/**
 * FIX C — CSS/JS Parity (P1/P2).
 *
 * thailotto.club visual parity:
 *   CSS: ball-pop 0.6s, ball-glow, colon-blink
 *   JS: YouTube autoplay=1&mute=1 + allow autoplay
 *   Assets: p1.png..p6.png PNG copies
 */
final class CssJsParityTest extends TestCase
{
    public function test_home_css_has_ball_pop_animation(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/home.css'));

        $this->assertStringContainsString('@keyframes ball-pop', $css, 'Missing @keyframes ball-pop');
        $this->assertStringContainsString('ball-pop 0.6s', $css, 'Missing ball-pop 0.6s usage');
        $this->assertStringContainsString('@keyframes ball-glow', $css, 'Missing @keyframes ball-glow');
        $this->assertStringContainsString('tl-lottery-ball-3d', $css, 'Missing ball selector for animation');
    }

    public function test_home_css_has_colon_blink(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/home.css'));

        $this->assertStringContainsString('@keyframes colon-blink', $css, 'Missing @keyframes colon-blink');
        $this->assertStringContainsString('colon-blink 1s', $css, 'Missing colon-blink 1s usage');
        $this->assertStringContainsString('is-ticking', $css, 'Missing is-ticking helper');
    }

    public function test_home_css_retains_reduced_motion(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/home.css'));

        $this->assertStringContainsString('prefers-reduced-motion', $css, 'Reduced motion must be preserved');
    }

    /**
     * The live-draw enhancement must not take over playback.
     *
     * This used to grep the module for the STRINGS 'autoplay', 'mute' and the
     * function name 'ensureAutoplay', which asserted an implementation instead of
     * a behaviour — and the implementation it demanded was the wrong one. The
     * module forced `autoplay=1&mute=1` onto the server-rendered iframe and added
     * `allow="autoplay"` itself, so a stream the operator had stored with
     * `autoplay=0` started playing on a public page nobody asked to start one on.
     * home.blade.php already renders both the embed URL and the full allow list
     * ("autoplay; encrypted-media; picture-in-picture; fullscreen"), so playback
     * permissions are the server's to grant, not this script's.
     *
     * Asserting the absence of a rewrite is also what tests/js/home-live-draw.test.mjs
     * does behaviourally, by running the module against a fake DOM and comparing
     * the resulting src byte for byte. The two suites disagreed, and the grep won
     * only because nothing read the rendered URL. This now checks the same
     * contract from the PHP side.
     */
    public function test_live_draw_js_never_rewrites_the_server_rendered_player(): void
    {
        $js = (string) file_get_contents(base_path('resources/js/home/live-draw.js'));

        // Assertions about what CODE does must not read the comments. The module
        // carries a docblock explaining exactly why it no longer forces autoplay,
        // and grepping the raw file made that explanation fail the very check it
        // documents. Comments are stripped first, so every assertion below is
        // about executable source only.
        $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $js);

        // It is a disenhancement: the only DOM removal it may perform is of media
        // the server did not vouch for.
        $this->assertStringContainsString('removeMedia', $code, 'the module must still strip unvouched media');
        $this->assertStringContainsString('iframe', $code, 'the module must still work on rendered media elements');

        // It must not write a src or widen a permission attribute.
        $this->assertStringNotContainsString("setAttribute('src'", $code, 'the rendered embed URL must not be rewritten');
        $this->assertStringNotContainsString('setAttribute("src"', $code, 'the rendered embed URL must not be rewritten');
        $this->assertStringNotContainsString("setAttribute('allow'", $code, 'playback permissions belong to the server-rendered markup');
        $this->assertStringNotContainsString('setAttribute("allow"', $code, 'playback permissions belong to the server-rendered markup');

        // And it must not force the parameters that made the old behaviour wrong.
        $this->assertStringNotContainsString('autoplay=1', $code, 'the module must not force autoplay on a stream the operator configured');
        $this->assertStringNotContainsString('mute=1', $code, 'the module must not force mute on a stream the operator configured');
        $this->assertStringNotContainsString('autoplay', $code, 'the module must not touch the autoplay parameter at all');
    }

    public function test_brand_png_assets_exist(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $png = base_path("public/images/brands/p{$i}.png");
            $svg = base_path("public/images/brands/p{$i}.svg");

            $this->assertFileExists($png, "Missing p{$i}.png — thailotto.club parity requires PNG");
            $this->assertFileExists($svg, "Missing p{$i}.svg source");
            $this->assertGreaterThan(0, filesize($png), "p{$i}.png is empty");
        }
    }

    public function test_hero_banner_asset_exists(): void
    {
        $this->assertFileExists(base_path('public/images/home/hero-banner.png'), 'Missing hero-banner.png');
        $this->assertGreaterThan(100000, filesize(base_path('public/images/home/hero-banner.png')), 'hero-banner.png too small — must be 1.5MB parity asset');
    }

    public function test_home_page_uses_hero_banner_with_eager(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('hero-banner.png', $content, 'Missing hero-banner.png in home page');
        // Eager loading for hero
        $this->assertStringContainsString('fetchpriority', strtolower($content) ?: '', 'Missing fetchpriority for hero');
    }
}
