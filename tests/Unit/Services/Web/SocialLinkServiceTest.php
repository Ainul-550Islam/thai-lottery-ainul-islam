<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Web;

use App\Services\Web\SocialLinkService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialLinkServiceTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function accepted(): iterable
    {
        yield 'facebook page' => ['facebook', 'https://www.facebook.com/SomePage', 'https://www.facebook.com/SomePage'];
        yield 'facebook bare host' => ['facebook', 'https://fb.com/SomePage', 'https://fb.com/SomePage'];
        yield 'youtube channel' => ['youtube', 'https://www.youtube.com/@somechannel', 'https://www.youtube.com/@somechannel'];
        yield 'youtu.be' => ['youtube', 'https://youtu.be/abc', 'https://youtu.be/abc'];
        yield 'tiktok profile' => ['tiktok', 'https://www.tiktok.com/@some.user', 'https://www.tiktok.com/@some.user'];
        yield 'surrounding whitespace is trimmed' => ['tiktok', "  https://www.tiktok.com/@u \n", 'https://www.tiktok.com/@u'];
    }

    #[DataProvider('accepted')]
    public function test_valid_platform_links_are_returned_trimmed(string $platform, string $url, string $expected): void
    {
        $this->assertSame($expected, SocialLinkService::validate($platform, $url));
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function rejected(): iterable
    {
        yield 'unset' => ['facebook', null];
        yield 'empty' => ['facebook', ''];
        yield 'plain http' => ['facebook', 'http://www.facebook.com/SomePage'];
        yield 'javascript' => ['facebook', 'javascript:alert(1)'];
        yield 'wrong platform domain' => ['facebook', 'https://www.youtube.com/@somechannel'];
        yield 'lookalike domain' => ['facebook', 'https://facebook.com.evil.example/SomePage'];
        yield 'credentials' => ['facebook', 'https://user:pw@www.facebook.com/SomePage'];
        yield 'non default port' => ['facebook', 'https://www.facebook.com:8443/SomePage'];
        yield 'internal whitespace' => ['facebook', 'https://www.facebook.com/Some Page'];
        yield 'nul byte' => ['facebook', "https://www.facebook.com/SomePage\x00"];
        yield 'unknown platform' => ['myspace', 'https://myspace.com/someone'];
    }

    #[DataProvider('rejected')]
    public function test_unsafe_or_off_domain_links_resolve_to_null(string $platform, ?string $url): void
    {
        $this->assertNull(SocialLinkService::validate($platform, $url));
    }
}
