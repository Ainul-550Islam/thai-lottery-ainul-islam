<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\BotDetectionMiddleware;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\NullLogger;
use Tests\TestCase;

final class BotDetectionMiddlewareTest extends TestCase
{
    /** @param array<string, mixed> $bot */
    private function middleware(array $bot): BotDetectionMiddleware
    {
        return new BotDetectionMiddleware(new Repository(['bot' => $bot]), app(), new NullLogger);
    }

    private function botPost(): Request
    {
        return Request::create('/api/v1/auth/login', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'curl/7.88.1',
            'REMOTE_ADDR' => '198.51.100.1',
        ]);
    }

    public function test_observation_is_off_by_default_and_never_blocks(): void
    {
        $request = $this->botPost();
        $response = $this->middleware([])->handle($request, fn () => new Response('PASS', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('disabled', $request->attributes->get('bot.observation_status'));
    }

    public function test_non_post_requests_are_skipped(): void
    {
        $request = Request::create('/api/v1/auth/login', 'GET');
        $response = $this->middleware(['enabled' => true, 'http_observation' => ['enabled' => true]])
            ->handle($request, fn () => new Response('PASS', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('skipped_method', $request->attributes->get('bot.observation_status'));
    }

    public function test_enforcement_mode_is_refused_not_silently_allowed(): void
    {
        $called = false;
        $request = $this->botPost();
        $response = $this->middleware([
            'enabled' => true,
            'mode' => 'enforce',
            'http_observation' => ['enabled' => true],
        ])->handle($request, function () use (&$called) {
            $called = true;

            return new Response('PASS', 200);
        });

        $this->assertFalse($called);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('configuration_error', $request->attributes->get('bot.observation_status'));
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('bot_observation_configuration_error', $body['error']['code']);
    }

    public function test_non_boolean_switch_is_a_configuration_error(): void
    {
        $response = $this->middleware(['http_observation' => ['enabled' => 'yes']])
            ->handle($this->botPost(), fn () => new Response('PASS', 200));

        $this->assertSame(503, $response->getStatusCode());
    }
}
