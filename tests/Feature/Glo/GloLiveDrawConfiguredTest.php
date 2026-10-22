<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Exceptions\GloDealerException;
use App\Services\Lottery\GloLiveDrawService;
use App\Services\Lottery\YouTubeEmbedUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The live draw is NOT_CONFIGURED by default. It becomes CONFIGURED only when the operator
 * sets the mode and an allowlisted YouTube URL; the home page then renders a sandboxed
 * youtube-nocookie iframe. Every other input must keep the honest NOT_CONFIGURED state.
 */
final class GloLiveDrawConfiguredTest extends TestCase
{
    use RefreshDatabase;

    private const RAW_URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    private const EMBED = 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function configureLive(string $mode, ?string $embed): void
    {
        config([
            'glo.result_experience.live_draw.mode' => $mode,
            'glo.result_experience.live_draw.embed_url' => $embed,
        ]);

        Cache::flush();
    }

    public function test_default_configuration_reports_not_configured(): void
    {
        $status = app(GloLiveDrawService::class)->liveStatus();

        $this->assertSame('not_configured', $status['live_status']);
        $this->assertSame('NOT_CONFIGURED', $status['source_state']);
        $this->assertNull($status['embed_url']);
    }

    public function test_configured_mode_with_allowed_youtube_url_returns_normalised_embed(): void
    {
        $this->configureLive('configured', self::RAW_URL);

        $status = app(GloLiveDrawService::class)->liveStatus();

        $this->assertSame('configured', $status['live_status']);
        $this->assertSame('OFFICIAL_SOURCE_CONFIGURED', $status['source_state']);
        $this->assertSame('youtube', $status['provider']);
        $this->assertSame(self::EMBED, $status['embed_url']);
    }

    public function test_raw_operator_input_is_never_returned_verbatim(): void
    {
        $this->configureLive('configured', 'https://youtu.be/dQw4w9WgXcQ?si=tracking-token&t=5');

        $status = app(GloLiveDrawService::class)->liveStatus();

        $this->assertSame(self::EMBED, $status['embed_url']);
        $this->assertStringNotContainsString('tracking-token', (string) $status['embed_url']);
    }

    public function test_configured_mode_with_foreign_url_falls_back_to_not_configured(): void
    {
        $this->configureLive('configured', 'https://evil.example.com/watch?v=dQw4w9WgXcQ');

        $status = app(GloLiveDrawService::class)->liveStatus();

        $this->assertSame('not_configured', $status['live_status']);
        $this->assertNull($status['embed_url']);
        $this->assertStringContainsString('not an allowed YouTube video or live URL', $status['message']);
    }

    public function test_allowed_url_without_configured_mode_stays_not_configured(): void
    {
        $this->configureLive('not_configured', self::RAW_URL);

        $status = app(GloLiveDrawService::class)->liveStatus();

        $this->assertSame('not_configured', $status['live_status']);
        $this->assertNull($status['embed_url']);
    }

    public function test_home_page_renders_sandboxed_nocookie_iframe_when_configured(): void
    {
        $this->configureLive('configured', self::RAW_URL);

        $content = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('LIVE DRAW CONFIGURED', $content);
        $this->assertStringContainsString('<iframe', $content);
        $this->assertStringContainsString('src="'.self::EMBED.'"', $content);
        $this->assertStringContainsString('loading="lazy"', $content);
        $this->assertStringContainsString('sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"', $content);
        $this->assertStringNotContainsString('LIVE DRAW NOT_CONFIGURED', $content);
    }

    public function test_home_page_renders_no_iframe_when_not_configured(): void
    {
        $this->configureLive('not_configured', null);

        $content = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<iframe', $content);
        $this->assertStringNotContainsString('youtube-nocookie.com/embed/', $content);
        $this->assertStringContainsString('LIVE DRAW NOT_CONFIGURED', $content);
    }

    public function test_api_status_reports_configured_embed_for_operators(): void
    {
        $this->configureLive('configured', self::RAW_URL);

        $this->getJson('/api/v1/glo/results/live')
            ->assertOk()
            ->assertJsonPath('data.live_status', 'configured')
            ->assertJsonPath('data.provider', 'youtube')
            ->assertJsonPath('data.embed_url', YouTubeEmbedUrl::normalize(self::RAW_URL));
    }

    public function test_replays_still_throw_when_replay_catalog_is_not_configured(): void
    {
        $this->configureLive('configured', self::RAW_URL);

        $this->expectException(GloDealerException::class);

        app(GloLiveDrawService::class)->replays();
    }
}
