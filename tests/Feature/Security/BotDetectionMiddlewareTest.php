<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Events\BotBlocked;
use App\Events\BotSuspected;
use App\Http\Middleware\BotDetectionMiddleware;
use App\Services\Bot\BotDetectionService;
use App\Services\Bot\BotScoreCalculator;
use App\Services\Bot\Signals\UserAgentSignal;
use App\Services\Network\IpCidrMatcher;
use App\Services\Network\TrustedClientIpResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BotDetectionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_bot_telemetry_in_log_only_mode_without_blocking(): void
    {
        Event::fake([BotSuspected::class, BotBlocked::class]);
        config(['bot.mode' => 'log_only']);

        $botService = new BotDetectionService([new UserAgentSignal()], new BotScoreCalculator());
        $middleware = new BotDetectionMiddleware($botService, new TrustedClientIpResolver(new IpCidrMatcher()));

        $request = Request::create('/api/v1/auth/login', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'curl/7.88.1',
            'REMOTE_ADDR' => '198.51.100.1',
        ]);

        $response = $middleware->handle($request, fn() => new Response('PASS', 200));

        $this->assertSame(200, $response->getStatusCode());
        Event::assertDispatched(BotSuspected::class);
        Event::assertNotDispatched(BotBlocked::class);
    }

    public function test_blocks_aggressive_bot_in_enforce_mode(): void
    {
        Event::fake([BotBlocked::class]);
        config(['bot.mode' => 'enforce']);
        config(['bot.block_threshold' => 20]); // low threshold for test

        $botService = new BotDetectionService([new UserAgentSignal()], new BotScoreCalculator());
        $middleware = new BotDetectionMiddleware($botService, new TrustedClientIpResolver(new IpCidrMatcher()));

        $request = Request::create('/api/v1/auth/login', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'sqlmap/1.6.12',
            'REMOTE_ADDR' => '198.51.100.2',
        ]);

        $response = $middleware->handle($request, fn() => new Response('PASS', 200));

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('AUTOMATED_REQUEST_BLOCKED', $data['error_code']);
        Event::assertDispatched(BotBlocked::class);
    }
}
