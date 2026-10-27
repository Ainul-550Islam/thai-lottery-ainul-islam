<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\Bot\Signals\FormTimingSignal;
use App\Services\Bot\Signals\HeaderConsistencySignal;
use App\Services\Bot\Signals\UserAgentSignal;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class BotSignalsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_user_agent_signal_detects_empty_and_automated_tools(): void
    {
        $signal = new UserAgentSignal;

        $req1 = Request::create('/api/v1/auth/login', 'POST', [], [], [], ['HTTP_USER_AGENT' => '']);
        $res1 = $signal->score($req1);
        $this->assertSame(100, $res1->strength);
        $this->assertContains('UA_MISSING', $res1->reasons);

        $req2 = Request::create('/api/v1/auth/login', 'POST', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (HeadlessChrome/112.0)']);
        $res2 = $signal->score($req2);
        $this->assertSame(100, $res2->strength);
        $this->assertContains('UA_AUTOMATION_MARKER', $res2->reasons);

        $req3 = Request::create('/api/v1/auth/login', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148',
        ]);
        $res3 = $signal->score($req3);
        $this->assertSame(0, $res3->strength);
    }

    public function test_header_consistency_signal(): void
    {
        $signal = new HeaderConsistencySignal;

        $req1 = Request::create('/api/v1/orders', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'HTTP_ACCEPT' => '',
            'HTTP_ACCEPT_LANGUAGE' => '',
        ]);
        $res1 = $signal->score($req1);
        $this->assertGreaterThan(0, $res1->strength);

        $req2 = Request::create('/api/v1/orders', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'HTTP_ACCEPT' => 'application/json, text/plain, */*',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9,th;q=0.8',
        ]);
        $res2 = $signal->score($req2);
        $this->assertSame(0, $res2->strength);
    }

    public function test_form_timing_signal_detects_instant_submission(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 08:00:00', 'UTC'));

        $config = new Repository([
            'bot' => [
                'form_timing' => [
                    'field' => '_bot_ft',
                    'min_seconds' => 2,
                    'ttl_seconds' => 600,
                    'max_tokens' => 5,
                ],
            ],
        ]);

        $signal = new FormTimingSignal($config);
        $session = new Store('test-session', new ArraySessionHandler(120));
        $session->setId(str_repeat('a', 40));

        $renderReq = Request::create('/register', 'GET');
        $renderReq->setLaravelSession($session);
        $token = $signal->issue($renderReq, 'register');

        // Instant submission (0s elapsed < min_seconds=2) triggers timing signal
        $fastReq = Request::create('/register', 'POST', ['_bot_ft' => $token]);
        $fastReq->setLaravelSession($session);
        $fastReq->attributes->set('bot.form_timing_required', true);
        $fastReq->attributes->set('bot.form_purpose', 'register');
        $resFast = $signal->score($fastReq);
        $this->assertGreaterThan(0, $resFast->strength);

        // Human submission after 4 seconds passes cleanly
        $token2 = $signal->issue($renderReq, 'register');
        Carbon::setTestNow(Carbon::parse('2026-10-21 08:00:04', 'UTC'));
        $humanReq = Request::create('/register', 'POST', ['_bot_ft' => $token2]);
        $humanReq->setLaravelSession($session);
        $humanReq->attributes->set('bot.form_timing_required', true);
        $humanReq->attributes->set('bot.form_purpose', 'register');
        $resHuman = $signal->score($humanReq);
        $this->assertSame(0, $resHuman->strength);
    }
}
