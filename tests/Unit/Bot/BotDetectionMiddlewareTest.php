<?php

declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Http\Middleware\BotDetectionMiddleware;
use App\Services\Bot\BotDetectionService;
use App\Services\Bot\BotScoreCalculator;
use App\Services\Bot\Contracts\BotSignal;
use App\Services\Bot\DTOs\SignalResult;
use App\Services\Bot\Enums\BotDecision;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;

final class BotDetectionMiddlewareTest extends TestCase
{
    private function config(): Repository
    {
        return new Repository(['bot' => [
            'enabled' => true, 'mode' => 'log', 'http_observation' => ['enabled' => true],
            'signals' => ['a' => ['enabled' => true, 'mode' => 'log', 'weight' => 100]],
        ]]);
    }

    public function test_suspicious_observation_preserves_exact_downstream_response_and_body(): void
    {
        $config = $this->config();
        $signal = $this->createMock(BotSignal::class);
        $signal->expects(self::once())->method('score')->willReturn(new SignalResult('a', 100, ['TEST_RISK']));
        $container = new Container;
        $container->instance(BotDetectionService::class, new BotDetectionService($config, new BotScoreCalculator($config), new NullLogger, ['a' => $signal]));
        $middleware = new BotDetectionMiddleware($config, $container, new NullLogger);
        $request = Request::create('/login', 'POST', ['login' => 'example', 'password' => 'not-logged']);
        $response = new Response('existing validation error', 422, ['X-Existing' => 'preserved']);
        self::assertSame($response, $middleware->handle($request, function (Request $received) use ($response): Response {
            self::assertSame('not-logged', $received->input('password'));

            return $response;
        }));
        self::assertSame('observed', $request->attributes->get('bot.observation_status'));
        self::assertSame(BotDecision::Allow, $request->attributes->get('bot.decision')->decision);
        self::assertFalse($response->headers->has('X-Bot-Score'));
    }

    public static function bypassCases(): array
    {
        return [['bot.http_observation.enabled', false, 'POST', 'disabled'], ['bot.enabled', false, 'POST', 'disabled'], ['bot.enabled', true, 'GET', 'skipped_method']];
    }

    #[DataProvider('bypassCases')]
    public function test_bypass_does_not_resolve_engine(string $key, bool $value, string $method, string $status): void
    {
        $config = $this->config();
        $config->set($key, $value);
        $container = new Container;
        $container->bind(BotDetectionService::class, fn () => throw new \RuntimeException('Must not resolve.'));
        $request = Request::create('/login', $method);
        $response = new Response('ok');
        self::assertSame($response, (new BotDetectionMiddleware($config, $container, new NullLogger))->handle($request, fn () => $response));
        self::assertSame($status, $request->attributes->get('bot.observation_status'));
        self::assertFalse($container->resolved(BotDetectionService::class));
    }

    public static function badConfiguration(): array
    {
        return [['bot.mode', 'enforce'], ['bot.mode', 'invalid'], ['bot.http_observation.enabled', 'false'], ['bot.enabled', 'true']];
    }

    #[DataProvider('badConfiguration')]
    public function test_unsupported_or_invalid_mode_is_explicit_503(string $key, mixed $value): void
    {
        $config = $this->config();
        $config->set($key, $value);
        $request = Request::create('/login', 'POST');
        $response = (new BotDetectionMiddleware($config, new Container, new NullLogger))->handle($request, function (): Response {
            self::fail('Downstream must not run on invalid deployment config.');
        });
        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('bot_observation_configuration_error', $response->getContent());
        self::assertSame('configuration_error', $request->attributes->get('bot.observation_status'));
    }

    public function test_engine_and_logger_failure_are_observation_only(): void
    {
        $container = new Container;
        $container->bind(BotDetectionService::class, fn () => throw new \RuntimeException('secret-SQL-password'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('bot.http_observation_unavailable')->willThrowException(new \RuntimeException('disk full'));
        $request = Request::create('/register', 'POST');
        $response = new Response('original', 201);
        self::assertSame($response, (new BotDetectionMiddleware($this->config(), $container, $logger))->handle($request, fn () => $response));
        self::assertSame('unavailable', $request->attributes->get('bot.observation_status'));
        self::assertStringNotContainsString('secret', $response->getContent());
    }

    public function test_downstream_exceptions_are_not_swallowed(): void
    {
        $config = $this->config();
        $signal = $this->createMock(BotSignal::class);
        $signal->expects(self::once())->method('score')->willReturn(new SignalResult('a', 0));
        $container = new Container;
        $container->instance(BotDetectionService::class, new BotDetectionService($config, new BotScoreCalculator($config), new NullLogger, ['a' => $signal]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('original controller error');
        (new BotDetectionMiddleware($config, $container, new NullLogger))->handle(Request::create('/login', 'POST'), fn () => throw new \RuntimeException('original controller error'));
    }

    public function test_partial_assessment_is_not_labelled_complete(): void
    {
        $config = $this->config();
        $signal = $this->createMock(BotSignal::class);
        $signal->method('score')->willThrowException(new \RuntimeException('cache down'));
        $container = new Container;
        $container->instance(BotDetectionService::class, new BotDetectionService($config, new BotScoreCalculator($config), new NullLogger, ['a' => $signal]));
        $request = Request::create('/login', 'POST');
        (new BotDetectionMiddleware($config, $container, new NullLogger))->handle($request, fn () => new Response('ok'));
        self::assertSame('partial', $request->attributes->get('bot.observation_status'));
    }
}
