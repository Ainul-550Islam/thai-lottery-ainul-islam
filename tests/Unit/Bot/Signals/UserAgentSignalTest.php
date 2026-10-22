<?php

declare(strict_types=1);

namespace Tests\Unit\Bot\Signals;

use App\Services\Bot\Signals\UserAgentSignal;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserAgentSignalTest extends TestCase
{
    public static function agents(): array
    {
        return [
            [null, 100, ['UA_MISSING']],
            ['', 100, ['UA_MISSING']],
            ['   ', 100, ['UA_MISSING']],
            ['curl/8.0', 100, ['UA_AUTOMATION_MARKER']],
            ['PYTHON-REQUESTS/2.0', 100, ['UA_AUTOMATION_MARKER']],
            ['Mozilla/5.0 HeadlessChrome/120', 100, ['UA_AUTOMATION_MARKER']],
            ['Wget/1.0', 100, ['UA_AUTOMATION_MARKER']],
            ['Playwright', 100, ['UA_AUTOMATION_MARKER']],
            ['Googlebot curl/8', 100, ['UA_AUTOMATION_MARKER']],
            ['Mozilla/5.0 Chrome/120 Safari/537', 0, []],
            ['MyMobileApplication/1', 0, []],
            [str_repeat('a', 2049), 100, ['UA_OVERSIZED']],
        ];
    }

    #[DataProvider('agents')]
    public function test_agent_evidence(?string $ua, int $strength, array $reasons): void
    {
        $request = Request::create('/');
        $request->headers->remove('User-Agent');
        if ($ua !== null) {
            $request->headers->set('User-Agent', $ua);
        }
        $result = (new UserAgentSignal)->score($request);
        self::assertSame('user_agent', $result->signal);
        self::assertSame($strength, $result->strength);
        self::assertSame($reasons, $result->reasons);
    }
}
