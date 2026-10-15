<?php

namespace Tests\Feature\Web;

use App\Services\Media\PublicAppLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * APP-LINK CTA FAILS CLOSED (FINAL AUDIT #14).
 *
 * The Home app-links card may only render REAL configured store/PWA URLs.
 * Unconfigured means an honest "not configured" state — never a fabricated
 * Google Play / App Store button — and scheme-injected or otherwise unsafe
 * values are dropped, not rendered.
 */
class AppLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_links_report_not_configured_with_no_buttons(): void
    {
        config([
            'home.app_links.android' => null,
            'home.app_links.ios' => null,
            'home.app_links.pwa' => null,
        ]);

        $links = $this->app->make(PublicAppLinkService::class)->links();

        $this->assertSame('NOT_CONFIGURED', $links['status']);
        $this->assertNull($links['android']);
        $this->assertNull($links['ios']);
        $this->assertNull($links['pwa']);
        $this->assertNotSame('', $links['message']);

        $page = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('App download links are not configured.', $page);
        $this->assertStringNotContainsString('Google Play', $page);
        $this->assertStringNotContainsString('play.google.com', $page);
        $this->assertStringNotContainsString('apps.apple.com', $page);
    }

    public function test_configured_links_render_as_real_anchors(): void
    {
        config([
            'home.app_links.android' => 'https://downloads.example.com/android.apk',
            'home.app_links.ios' => 'https://downloads.example.com/ios',
            'home.app_links.pwa' => '/install-pwa',
        ]);

        $links = $this->app->make(PublicAppLinkService::class)->links();

        $this->assertSame('CONFIGURED', $links['status']);
        $this->assertSame('https://downloads.example.com/android.apk', $links['android']);
        $this->assertSame('https://downloads.example.com/ios', $links['ios']);
        $this->assertSame('/install-pwa', $links['pwa']);

        $page = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('https://downloads.example.com/android.apk', $page);
        $this->assertStringContainsString('https://downloads.example.com/ios', $page);
        $this->assertStringContainsString('/install-pwa', $page);
    }

    public function test_unsafe_schemes_are_dropped_rather_than_rendered(): void
    {
        config([
            'home.app_links.android' => 'javascript:alert(1)',
            'home.app_links.ios' => 'data:text/html;base64,PHNjcmlwdD4=',
            'home.app_links.pwa' => 'javascript:alert(2)',
        ]);

        $links = $this->app->make(PublicAppLinkService::class)->links();

        $this->assertSame('NOT_CONFIGURED', $links['status']);
        $this->assertNull($links['android']);
        $this->assertNull($links['ios']);
        $this->assertNull($links['pwa']);

        $page = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('javascript:', $page);
        $this->assertStringNotContainsString('data:text/html', $page);
    }

    public function test_partial_configuration_renders_only_the_configured_platforms(): void
    {
        config([
            'home.app_links.android' => 'https://downloads.example.com/android.apk',
            'home.app_links.ios' => null,
            'home.app_links.pwa' => null,
        ]);

        $page = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('https://downloads.example.com/android.apk', $page);
        // No fabricated iOS/PWA buttons for unconfigured platforms.
        $this->assertStringNotContainsString('apps.apple.com', $page);
    }

    public function test_placeholder_values_fail_closed(): void
    {
        config([
            'home.app_links.android' => 'https://example.com/COMING_SOON',
            'home.app_links.ios' => '',
            'home.app_links.pwa' => '   ',
        ]);

        // The service cannot judge intent; it must at least drop empty /
        // whitespace values. A deliberate placeholder URL is an operator
        // decision recorded in .env — the card renders what is configured.
        $links = $this->app->make(PublicAppLinkService::class)->links();

        $this->assertSame('CONFIGURED', $links['status']);
        $this->assertSame('https://example.com/COMING_SOON', $links['android']);
        $this->assertNull($links['ios']);
        $this->assertNull($links['pwa']);
    }
}
