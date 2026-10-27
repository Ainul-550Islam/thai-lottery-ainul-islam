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

    public function test_live_draw_js_has_autoplay(): void
    {
        $js = (string) file_get_contents(base_path('resources/js/home/live-draw.js'));

        $this->assertStringContainsString('autoplay', $js, 'Missing autoplay in live-draw.js');
        $this->assertStringContainsString('mute', $js, 'Missing mute in live-draw.js');
        $this->assertStringContainsString('ensureAutoplay', $js, 'Missing ensureAutoplay function');
        $this->assertStringContainsString('youtube.com', $js, 'Missing youtube.com check');
        $this->assertStringContainsString('youtu.be', $js, 'Missing youtu.be check');
        // Must not invent media — check it guards with iframe existence
        $this->assertStringContainsString('iframe', $js);
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
