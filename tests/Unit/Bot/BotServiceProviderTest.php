<?php

declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Providers\BotServiceProvider;
use App\Services\Bot\BotDetectionService;
use App\Services\Bot\Enums\BotDecision;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class BotServiceProviderTest extends TestCase
{
    private Application $app;

    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->app = new Application(dirname(__DIR__, 3));
        $this->app->instance('config', new Repository(['bot' => [
            'enabled' => true, 'mode' => 'log',
            'signals' => ['user_agent' => ['enabled' => true, 'mode' => 'log', 'weight' => 100]],
        ]]));
        $this->app->instance(LoggerInterface::class, new NullLogger);
        // Any accidental eager dependency resolution fails this test immediately.
        $this->app->bind('db', fn () => throw new \RuntimeException('Database must not be resolved.'));
        $this->app->bind('cache', fn () => throw new \RuntimeException('Cache must not be resolved.'));
    }

    protected function tearDown(): void
    {
        $this->app->flush();
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_registration_is_lazy_and_enabled_signal_resolves(): void
    {
        (new BotServiceProvider($this->app))->register();
        self::assertFalse($this->app->resolved(BotDetectionService::class));
        $engine = $this->app->make(BotDetectionService::class);
        $request = Request::create('/login');
        $request->headers->set('User-Agent', 'curl/8');
        $result = $engine->detect($request);
        self::assertSame(100, $result->score);
        self::assertSame(BotDecision::Block, $result->assessedDecision);
        self::assertSame(BotDecision::Allow, $result->decision);
    }

    public function test_http_observation_defaults_to_opt_out(): void
    {
        $env = Env::getRepository();
        $old = $env->get('BOT_HTTP_OBSERVATION_ENABLED');
        $env->clear('BOT_HTTP_OBSERVATION_ENABLED');
        try {
            $settings = require dirname(__DIR__, 3).'/config/bot.php';
            self::assertFalse($settings['http_observation']['enabled']);
        } finally {
            $env->clear('BOT_HTTP_OBSERVATION_ENABLED');
            if ($old !== null) {
                $env->set('BOT_HTTP_OBSERVATION_ENABLED', $old);
            }
        }
    }

    public function test_scoped_instance_is_reused_then_reset(): void
    {
        (new BotServiceProvider($this->app))->register();
        $first = $this->app->make(BotDetectionService::class);
        self::assertSame($first, $this->app->make(BotDetectionService::class));
        $this->app->forgetScopedInstances();
        self::assertNotSame($first, $this->app->make(BotDetectionService::class));
    }

    public function test_global_disable_never_resolves_device_or_rate_stores(): void
    {
        $this->app->make('config')->set('bot.enabled', false);
        $this->app->make('config')->set('bot.signals.request_rate', ['enabled' => true, 'mode' => 'log', 'weight' => 35]);
        $this->app->make('config')->set('bot.signals.device_reuse', ['enabled' => true, 'mode' => 'log', 'weight' => 30]);
        (new BotServiceProvider($this->app))->register();
        self::assertSame(0, $this->app->make(BotDetectionService::class)->detect(Request::create('/'))->score);
    }
}
