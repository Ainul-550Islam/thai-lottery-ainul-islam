<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\Bot\Signals\DeviceReuseSignal;
use App\Services\Bot\Signals\FailedAuthenticationSignal;
use App\Services\Bot\Signals\FormTimingSignal;
use App\Services\Bot\Signals\HeaderConsistencySignal;
use App\Services\Bot\Signals\RequestVelocitySignal;
use App\Services\Bot\Signals\UserAgentSignal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\TestCase;

class BotSignalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_user_agent_signal_detects_empty_and_automated_tools(): void
    {
        $signal = new UserAgentSignal();

        // 1. Empty UA
        $req1 = Request::create('/api/v1/auth/login', 'POST', [], [], [], ['HTTP_USER_AGENT' => '']);
        $res1 = $signal->evaluate($req1);
        $this->assertTrue($res1['triggered']);
        $this->assertSame(20, $res1['score']);
        $this->assertSame('EMPTY_USER_AGENT', $res1['reason_code']);

        // 2. Headless Puppeteer / Selenium
        $req2 = Request::create('/api/v1/auth/login', 'POST', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (HeadlessChrome/112.0)']);
        $res2 = $signal->evaluate($req2);
        $this->assertTrue($res2['triggered']);
        $this->assertSame(20, $res2['score']);
        $this->assertSame('AUTOMATED_CLIENT_SIGNATURE', $res2['reason_code']);

        // 3. Normal Mobile / Web Browser
        $req3 = Request::create('/api/v1/auth/login', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148',
        ]);
        $res3 = $signal->evaluate($req3);
        $this->assertFalse($res3['triggered']);
        $this->assertSame(0, $res3['score']);
    }

    public function test_header_consistency_signal(): void
    {
        $signal = new HeaderConsistencySignal();

        // Standard browser with missing Accept header on web endpoint
        $req1 = Request::create('/api/v1/orders', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ]);
        $res1 = $signal->evaluate($req1);
        $this->assertTrue($res1['triggered']);
        $this->assertSame(15, $res1['score']);

        // Legitimate browser with full headers
        $req2 = Request::create('/api/v1/orders', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'HTTP_ACCEPT' => 'application/json, text/plain, */*',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9,th;q=0.8',
        ]);
        $res2 = $signal->evaluate($req2);
        $this->assertFalse($res2['triggered']);
    }

    public function test_form_timing_signal_detects_sub_800ms_submission(): void
    {
        $signal = new FormTimingSignal();

        // 1. Form submitted after only 250 milliseconds (superhuman bot)
        $req1 = Request::create('/api/v1/orders', 'POST', [
            '_form_rendered_at' => (string) (microtime(true) - 0.25),
        ]);
        $res1 = $signal->evaluate($req1);
        $this->assertTrue($res1['triggered']);
        $this->assertSame(20, $res1['score']);
        $this->assertSame('SUPERHUMAN_FORM_SUBMISSION_SPEED', $res1['reason_code']);

        // 2. Form submitted after 3.5 seconds (human)
        $req2 = Request::create('/api/v1/orders', 'POST', [
            '_form_rendered_at' => (string) (microtime(true) - 3.5),
        ]);
        $res2 = $signal->evaluate($req2);
        $this->assertFalse($res2['triggered']);
        $this->assertSame(0, $res2['score']);
    }
}
