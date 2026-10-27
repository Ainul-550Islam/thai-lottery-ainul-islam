<?php

declare(strict_types=1);

namespace Tests\Unit\Bot\Signals;

use App\Services\Bot\Signals\HeaderConsistencySignal;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeaderConsistencySignalTest extends TestCase
{
    public static function cases(): array
    {
        return [
            [[], false, 50, ['HEADER_ACCEPT_MISSING', 'HEADER_LANGUAGE_MISSING']],
            [[], true, 70, ['HEADER_ACCEPT_MISSING', 'HEADER_LANGUAGE_MISSING', 'HEADER_FETCH_METADATA_MISSING']],
            [['Accept' => 'application/json', 'Accept-Language' => 'en'], false, 0, []],
            [['Accept' => 'text/html', 'Accept-Language' => 'th', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Site' => 'none'], true, 0, []],
            [['Accept' => '*/*', 'Accept-Language' => 'en', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Site' => 'cross-site'], true, 0, []],
            [['Accept' => 'application/json', 'Accept-Language' => 'en', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'], false, 30, ['HEADER_NAVIGATION_ACCEPT_MISMATCH']],
            [['Accept' => 'text/html', 'Accept-Language' => 'en', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'document'], false, 40, ['HEADER_FETCH_MODE_DEST_MISMATCH']],
            [['Accept' => '*/*', 'Accept-Language' => 'en', 'Sec-Fetch-Mode' => 'bogus'], false, 40, ['HEADER_FETCH_MODE_INVALID']],
            [['Accept' => '*/*', 'Accept-Language' => 'en', 'Sec-Fetch-Site' => 'trusted'], false, 40, ['HEADER_FETCH_SITE_INVALID']],
            [['Sec-Fetch-Mode' => 'bogus', 'Sec-Fetch-Site' => 'trusted', 'Sec-Fetch-Dest' => 'document'], true, 100, ['HEADER_ACCEPT_MISSING', 'HEADER_LANGUAGE_MISSING', 'HEADER_FETCH_MODE_INVALID', 'HEADER_FETCH_SITE_INVALID', 'HEADER_FETCH_MODE_DEST_MISMATCH']],
        ];
    }

    #[DataProvider('cases')]
    public function test_header_evidence(array $headers, bool $browser, int $strength, array $reasons): void
    {
        $request = Request::create('/');
        $request->headers->replace($headers);
        $request->attributes->set('bot.browser_navigation', $browser);
        $result = (new HeaderConsistencySignal)->score($request);
        self::assertSame('header_consistency', $result->signal);
        self::assertSame($strength, $result->strength);
        self::assertSame($reasons, $result->reasons);
    }

    public function test_input_cannot_select_browser_context(): void
    {
        $request = Request::create('/', 'POST', ['bot.browser_navigation' => true]);
        $request->headers->replace(['Accept' => 'application/json', 'Accept-Language' => 'en']);
        self::assertSame(0, (new HeaderConsistencySignal)->score($request)->strength);
    }
}
