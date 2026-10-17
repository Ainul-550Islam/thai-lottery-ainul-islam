<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Services\Lottery\GloLiveDrawService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Live draw provider selection (YouTube, Facebook, TikTok), the home-page embed for each,
 * and the validated footer social links.
 */
final class LiveStreamProvidersTest extends TestCase
{
    use RefreshDatabase;

    private const FB_URL = 'https://www.facebook.com/watch/?v=123456789012345';

    private const TT_URL = 'https://www.tiktok.com/@some.user/video/7234567890123456789';

    private const TT_LIVE_URL = 'https://www.tiktok.com/@some.user/live';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::flush();
    }

    private function configureLive(string $provider, ?string $embed): void
    {
        config([
            'glo.result_experience.live_draw.mode' => 'configured',
            'glo.result_experience.live_draw.provider' => $provider,
            'glo.result_experience.live_draw.embed_url' => $embed,
        ]);

        Cache::flush();
    }

    public function test_facebook_live_resolves_to_plugin_embed(): void
    {
        $this->configureLive('facebook', self::FB_URL);

        $status = app(GloLiveDrawService::class)->liveStatus();

        $this->assertSame('configured', $status['live_status']);
        $this->assertSame('facebook', $status['provider']);
        $this->assertStringStartsWith('https://www.facebook.com/plugins/video.php?href=', (string) $status['embed_url']);
        $this->assertStringContainsString('show_text=false', (string) $status['embed_url']);
    }

    public function test_tiktok_video_resolves_to_v2_embed(): void
    {
        $this->configureLive('tiktok', self::TT_URL);

        $status = app(GloLiveDrawService::class)->liveStatus();

        $this->assertSame('configured', $status['live_status']);
        $this->assertSame('tiktok', $status['provider']);
        $this->assertSame('https://www.tiktok.com/embed/v2/7234567890123456789', $status['embed_url']);
    }

    public function test_tiktok_live_page_is_refused_with_a_reason(): void
    {
        $this->configureLive('tiktok', self::TT_LIVE_URL);

        $status = app(GloLiveDrawService::class)->liveStatus();

        $this->assertSame('not_configured', $status['live_status']);
        $this->assertNull($status['embed_url']);
        $this->assertStringContainsString('not an allowed TikTok video or live URL', $status['message']);
    }

    public function test_unsupported_provider_is_refused_with_a_reason(): void
    {
        $this->configureLive('myspace', 'https://myspace.com/someone');

        $status = app(GloLiveDrawService::class)->liveStatus();

        $this->assertSame('not_configured', $status['live_status']);
        $this->assertNull($status['provider']);
        $this->assertStringContainsString('provider is not supported', $status['message']);
    }

    public function test_provider_value_is_matched_case_insensitively(): void
    {
        $this->configureLive(' FACEBOOK ', self::FB_URL);

        $this->assertSame('facebook', app(GloLiveDrawService::class)->liveStatus()['provider']);
    }

    public function test_home_page_embeds_facebook_player_with_sandbox_and_lazy_loading(): void
    {
        $this->configureLive('facebook', self::FB_URL);

        $content = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<iframe', $content);
        $this->assertStringContainsString('https://www.facebook.com/plugins/video.php?href=', $content);
        $this->assertStringContainsString('loading="lazy"', $content);
        $this->assertStringContainsString('LIVE DRAW CONFIGURED', $content);
    }

    public function test_home_page_embeds_tiktok_video_when_configured(): void
    {
        $this->configureLive('tiktok', self::TT_URL);

        $content = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('src="https://www.tiktok.com/embed/v2/7234567890123456789"', $content);
    }

    public function test_home_page_never_renders_a_tiktok_live_page(): void
    {
        $this->configureLive('tiktok', self::TT_LIVE_URL);

        $content = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<iframe', $content);
        $this->assertStringNotContainsString('tiktok.com/@some.user/live', $content);
        $this->assertStringContainsString('LIVE DRAW NOT_CONFIGURED', $content);
    }

    public function test_footer_facebook_link_uses_validated_operator_url(): void
    {
        config(['social.facebook' => 'https://www.facebook.com/SomePage']);

        $content = (string) $this->get('/contact')->assertOk()->getContent();

        $this->assertStringContainsString('href="https://www.facebook.com/SomePage"', $content);
    }

    public function test_footer_facebook_link_ignores_off_domain_operator_url(): void
    {
        config(['social.facebook' => 'https://evil.example.com/phish']);

        $content = (string) $this->get('/contact')->assertOk()->getContent();

        $this->assertStringNotContainsString('evil.example.com', $content);
        $this->assertStringNotContainsString('>Facebook</a>', $content);
    }

    public function test_footer_facebook_link_on_lotto_discount_page_uses_validated_url(): void
    {
        config(['social.facebook' => 'https://www.facebook.com/SomePage']);

        $content = (string) $this->get('/lotto-discount')->assertOk()->getContent();

        $this->assertStringContainsString('href="https://www.facebook.com/SomePage"', $content);
    }
}
