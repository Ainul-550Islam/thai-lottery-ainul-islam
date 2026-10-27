<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Lottery;

use App\Services\Lottery\YouTubeEmbedUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YouTubeEmbedUrlTest extends TestCase
{
    private const VIDEO_ID = 'dQw4w9WgXcQ';

    private const EMBED = 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ';

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function allowedUrls(): iterable
    {
        yield 'watch on www' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'watch without www' => ['https://youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'watch with extra params' => ['https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ&t=10'];
        yield 'short link' => ['https://youtu.be/dQw4w9WgXcQ'];
        yield 'mobile watch' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'live path' => ['https://www.youtube.com/live/dQw4w9WgXcQ'];
        yield 'embed path' => ['https://www.youtube.com/embed/dQw4w9WgXcQ'];
        yield 'shorts path' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ'];
        yield 'nocookie embed' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        yield 'surrounding whitespace' => ["  https://youtu.be/dQw4w9WgXcQ \n"];
        yield 'uppercase scheme and host' => ['HTTPS://WWW.YOUTUBE.COM/watch?v=dQw4w9WgXcQ'];
        yield 'explicit default port' => ['https://www.youtube.com:443/watch?v=dQw4w9WgXcQ'];
    }

    #[DataProvider('allowedUrls')]
    public function test_allowed_youtube_urls_normalise_to_nocookie_embed(string $url): void
    {
        $this->assertSame(self::EMBED, YouTubeEmbedUrl::normalize($url));
        $this->assertSame(self::VIDEO_ID, YouTubeEmbedUrl::videoId($url));
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function rejectedUrls(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace only' => ["   \t "];
        yield 'plain http' => ['http://www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'data scheme' => ['data:text/html;base64,PGgxPg=='];
        yield 'foreign host' => ['https://evil.com/watch?v=dQw4w9WgXcQ'];
        yield 'lookalike suffix host' => ['https://youtube.com.evil.com/watch?v=dQw4w9WgXcQ'];
        yield 'lookalike prefix host' => ['https://evilyoutube.com/watch?v=dQw4w9WgXcQ'];
        yield 'credentials in url' => ['https://user:pw@www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'non default port' => ['https://www.youtube.com:8443/watch?v=dQw4w9WgXcQ'];
        yield 'short video id' => ['https://www.youtube.com/watch?v=short'];
        yield 'long video id' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQX'];
        yield 'quote injection in id' => ['https://www.youtube.com/watch?v=dQw4w9WgXc"Q'];
        yield 'angle bracket in id' => ['https://www.youtube.com/watch?v=dQw4w9Wg<cQ'];
        yield 'space inside url' => ['https://www.youtube.com/watch?v=dQw4w9 WgXcQ'];
        yield 'missing v parameter' => ['https://www.youtube.com/watch'];
        yield 'search page' => ['https://www.youtube.com/results?search_query=lottery'];
        yield 'channel page' => ['https://www.youtube.com/@someone'];
        yield 'extra path on short link' => ['https://youtu.be/dQw4w9WgXcQ/extra'];
        yield 'extra path on embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ/extra'];
        yield 'control character' => ["https://youtu.be/dQw4w9WgXcQ\x00"];
        yield 'overlong url' => ['https://youtu.be/'.str_repeat('a', 2100)];
    }

    #[DataProvider('rejectedUrls')]
    public function test_non_allowlisted_or_malformed_urls_are_rejected(?string $url): void
    {
        $this->assertNull(YouTubeEmbedUrl::normalize($url));
        $this->assertNull(YouTubeEmbedUrl::videoId($url));
    }

    public function test_embed_base_is_privacy_enhanced_https(): void
    {
        $this->assertSame('https://www.youtube-nocookie.com/embed/', YouTubeEmbedUrl::EMBED_BASE);
    }
}
