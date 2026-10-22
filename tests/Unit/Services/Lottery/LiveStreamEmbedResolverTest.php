<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Lottery;

use App\Enums\LiveStreamProvider;
use App\Services\Lottery\LiveStreamEmbedResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LiveStreamEmbedResolverTest extends TestCase
{
    private const FB_ID = '123456789012345';

    private const TT_ID = '7234567890123456789';

    private const FB_EMBED = 'https://www.facebook.com/plugins/video.php?href=https%3A%2F%2Fwww.facebook.com%2Fwatch%2F%3Fv%3D123456789012345&show_text=false&width=560';

    private const TT_EMBED = 'https://www.tiktok.com/embed/v2/7234567890123456789';

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function facebookAllowed(): iterable
    {
        yield 'watch' => ['https://www.facebook.com/watch/?v='.self::FB_ID];
        yield 'watch live' => ['https://www.facebook.com/watch/live/?v='.self::FB_ID];
        yield 'page video' => ['https://www.facebook.com/SomePage/videos/'.self::FB_ID.'/'];
        yield 'bare videos path' => ['https://www.facebook.com/videos/'.self::FB_ID];
        yield 'mobile reel' => ['https://m.facebook.com/reel/'.self::FB_ID];
        yield 'web host' => ['https://web.facebook.com/watch/?v='.self::FB_ID];
    }

    #[DataProvider('facebookAllowed')]
    public function test_allowed_facebook_urls_become_the_canonical_plugin_embed(string $url): void
    {
        $this->assertSame(self::FB_EMBED, LiveStreamEmbedResolver::resolve(LiveStreamProvider::Facebook, $url));
        $this->assertSame(self::FB_ID, LiveStreamEmbedResolver::facebookVideoId($url));
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function facebookRejected(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'plain http' => ['http://www.facebook.com/watch/?v='.self::FB_ID];
        yield 'foreign host' => ['https://evil.example.com/watch/?v='.self::FB_ID];
        yield 'lookalike suffix' => ['https://facebook.com.evil.example/watch/?v='.self::FB_ID];
        yield 'credentials' => ['https://user:pw@www.facebook.com/watch/?v='.self::FB_ID];
        yield 'non default port' => ['https://www.facebook.com:8443/watch/?v='.self::FB_ID];
        yield 'short id' => ['https://www.facebook.com/watch/?v=123'];
        yield 'non numeric id' => ['https://www.facebook.com/watch/?v=abcdefghij'];
        yield 'short link token' => ['https://fb.watch/abc123xyz/'];
        yield 'posts page' => ['https://www.facebook.com/SomePage/posts/1234567890123'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'space in url' => ['https://www.facebook.com/watch/?v=12345 6789012345'];
        yield 'nul byte' => ['https://www.facebook.com/watch/?v='.self::FB_ID."\x00"];
    }

    #[DataProvider('facebookRejected')]
    public function test_non_allowlisted_facebook_urls_are_rejected(?string $url): void
    {
        $this->assertNull(LiveStreamEmbedResolver::resolve(LiveStreamProvider::Facebook, $url));
        $this->assertNull(LiveStreamEmbedResolver::facebookVideoId($url));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function tiktokAllowed(): iterable
    {
        yield 'user video' => ['https://www.tiktok.com/@some.user/video/'.self::TT_ID];
        yield 'mobile user video' => ['https://m.tiktok.com/@some_user/video/'.self::TT_ID];
        yield 'embed v2' => ['https://www.tiktok.com/embed/v2/'.self::TT_ID];
        yield 'embed legacy' => ['https://m.tiktok.com/embed/'.self::TT_ID];
    }

    #[DataProvider('tiktokAllowed')]
    public function test_allowed_tiktok_video_urls_become_the_v2_embed(string $url): void
    {
        $this->assertSame(self::TT_EMBED, LiveStreamEmbedResolver::resolve(LiveStreamProvider::TikTok, $url));
        $this->assertSame(self::TT_ID, LiveStreamEmbedResolver::tiktokVideoId($url));
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function tiktokRejected(): iterable
    {
        yield 'null' => [null];
        yield 'live page has no embeddable id' => ['https://www.tiktok.com/@some.user/live'];
        yield 'short id' => ['https://www.tiktok.com/@some.user/video/123'];
        yield 'profile only' => ['https://www.tiktok.com/@some.user'];
        yield 'foreign host' => ['https://evil.example.com/@u/video/'.self::TT_ID];
        yield 'lookalike suffix' => ['https://tiktok.com.evil.example/@u/video/'.self::TT_ID];
        yield 'plain http' => ['http://www.tiktok.com/@u/video/'.self::TT_ID];
        yield 'bad username' => ['https://www.tiktok.com/@a/video/'.self::TT_ID];
        yield 'data scheme' => ['data:text/html,hello'];
    }

    #[DataProvider('tiktokRejected')]
    public function test_non_allowlisted_or_live_tiktok_urls_are_rejected(?string $url): void
    {
        $this->assertNull(LiveStreamEmbedResolver::resolve(LiveStreamProvider::TikTok, $url));
        $this->assertNull(LiveStreamEmbedResolver::tiktokVideoId($url));
    }

    public function test_youtube_provider_delegates_to_the_youtube_allowlist(): void
    {
        $this->assertSame(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            LiveStreamEmbedResolver::resolve(LiveStreamProvider::YouTube, 'https://youtu.be/dQw4w9WgXcQ?si=track'),
        );
        $this->assertNull(LiveStreamEmbedResolver::resolve(LiveStreamProvider::YouTube, 'https://www.facebook.com/watch/?v='.self::FB_ID));
    }

    public function test_provider_from_config_is_case_insensitive_and_rejects_unknown_values(): void
    {
        $this->assertSame(LiveStreamProvider::Facebook, LiveStreamProvider::fromConfig(' FaceBook '));
        $this->assertSame(LiveStreamProvider::TikTok, LiveStreamProvider::fromConfig('tiktok'));
        $this->assertNull(LiveStreamProvider::fromConfig('myspace'));
        $this->assertNull(LiveStreamProvider::fromConfig(null));
        $this->assertNull(LiveStreamProvider::fromConfig(['youtube']));
    }

    public function test_labels_are_human_readable(): void
    {
        $this->assertSame('YouTube', LiveStreamProvider::YouTube->label());
        $this->assertSame('Facebook', LiveStreamProvider::Facebook->label());
        $this->assertSame('TikTok', LiveStreamProvider::TikTok->label());
    }
}
