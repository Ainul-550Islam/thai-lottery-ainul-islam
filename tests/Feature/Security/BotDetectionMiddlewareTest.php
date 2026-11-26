<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\BotDetectionMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * The bot observation middleware, tested against the contract it actually implements.
 *
 * WHY THIS FILE WAS REWRITTEN
 *
 * The previous version constructed the middleware as
 * `new BotDetectionMiddleware($botService, $trustedIpResolver)` and asserted that an
 * aggressive user agent got a 403. Both halves were stale:
 *
 *   - the constructor has been `(ConfigRepository, Container, LoggerInterface)` for some
 *     time, so the class could not even be instantiated and both tests died with a
 *     TypeError before asserting anything; and
 *   - the middleware no longer blocks. It is an OBSERVATION adapter: it records a decision
 *     into `SecurityEvent` and explicitly refuses any mode other than 'log' rather than
 *     silently pretending to enforce. Asserting a 403 was asserting behaviour the class
 *     documents itself as not having.
 *
 * A test that cannot construct its subject proves nothing, and a test that asserts a
 * removed feature fails forever while looking like a regression. This file now pins the
 * real contract: observation happens, non-enforcement is refused loudly (503 with a stable
 * error code, not a silent allow), and the middleware never changes a login outcome.
 *
 * NOTE: this repair is outside the admin-backend scope; it is included because the stale
 * file made an honest verdict on the Security lane impossible.
 */
final class BotDetectionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private function middleware(): BotDetectionMiddleware
    {
        return app(BotDetectionMiddleware::class);
    }

    private function botRequest(string $userAgent = 'curl/7.88.1'): Request
    {
        return Request::create('/api/v1/auth/login', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => $userAgent,
            'REMOTE_ADDR' => '198.51.100.1',
        ]);
    }

    public function test_observation_is_off_by_default_and_never_changes_the_outcome(): void
    {
        config(['bot.http_observation.enabled' => false]);

        $request = $this->botRequest();

        $response = $this->middleware()->handle($request, fn (): Response => new Response('PASS', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('disabled', $request->attributes->get('bot.observation_status'));
        $this->assertNull($request->attributes->get('bot.decision'));
    }

    public function test_a_log_mode_post_is_observed_and_still_passes_through(): void
    {
        config([
            'bot.enabled' => true,
            'bot.http_observation.enabled' => true,
            'bot.mode' => 'log',
        ]);

        $request = $this->botRequest('sqlmap/1.6.12');

        $response = $this->middleware()->handle($request, fn (): Response => new Response('PASS', 200));

        $this->assertSame(200, $response->getStatusCode(), 'observation must never block a request');
        $this->assertSame('PASS', $response->getContent());

        $status = $request->attributes->get('bot.observation_status');

        $this->assertContains(
            $status,
            ['observed', 'partial', 'unavailable'],
            'a POST with observation enabled must record a status, not leave it blank',
        );
    }

    public function test_a_non_post_request_is_skipped(): void
    {
        config(['bot.enabled' => true, 'bot.http_observation.enabled' => true, 'bot.mode' => 'log']);

        $request = Request::create('/api/v1/draws', 'GET');

        $response = $this->middleware()->handle($request, fn (): Response => new Response('PASS', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('skipped_method', $request->attributes->get('bot.observation_status'));
    }

    public function test_an_unsupported_mode_is_refused_loudly_rather_than_silently_allowed(): void
    {
        config(['bot.enabled' => true, 'bot.http_observation.enabled' => true, 'bot.mode' => 'enforce']);

        $called = false;

        $response = $this->middleware()->handle($this->botRequest(), function () use (&$called): Response {
            $called = true;

            return new Response('PASS', 200);
        });

        $this->assertFalse($called, 'a configuration error must not reach the downstream handler');
        $this->assertSame(503, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true);

        $this->assertSame('bot_observation_configuration_error', $payload['error']['code']);
        $this->assertSame('This service is temporarily unavailable.', $payload['error']['message']);
    }

    public function test_a_non_boolean_configuration_is_treated_as_a_failure_not_as_off(): void
    {
        config(['bot.http_observation.enabled' => 'yes-please']);

        $response = $this->middleware()->handle($this->botRequest(), fn (): Response => new Response('PASS', 200));

        $this->assertSame(503, $response->getStatusCode());
    }
}
