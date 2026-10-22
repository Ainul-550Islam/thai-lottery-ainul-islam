<?php

declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Services\Bot\BotScoreCalculator;
use App\Services\Bot\DeviceFingerprintService;
use App\Services\Bot\DTOs\SignalResult;
use App\Services\Bot\Enums\BotDecision;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Env;
use PHPUnit\Framework\TestCase;

final class BotConfigurationTest extends TestCase
{
    public function test_actual_config_defaults_to_observation_and_services_resolve(): void
    {
        $keys = ['BOT_ENABLED', 'BOT_MODE', 'BOT_CHALLENGE_THRESHOLD', 'BOT_BLOCK_THRESHOLD', 'BOT_FINGERPRINT_KEY'];
        $env = Env::getRepository();
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = $env->get($key);
            $env->clear($key);
        }

        try {
            $settings = require dirname(__DIR__, 3).'/config/bot.php';
            self::assertSame('log', $settings['mode']);
            self::assertTrue($settings['enabled']);
            self::assertNull($settings['fingerprint']['key']);
            self::assertFalse($settings['search_bots']['allow_user_agent_only_bypass']);
            self::assertSame(40, $settings['thresholds']['challenge']);
            self::assertSame(80, $settings['thresholds']['block']);

            $signals = [];
            foreach ($settings['signals'] as $name => $signal) {
                self::assertSame('log', $signal['mode']);
                $signals[] = new SignalResult($name, 100, ['TEST_SIGNAL']);
            }
            $container = new Container;
            $container->instance(ConfigRepository::class, new Repository(['bot' => $settings]));
            $calculator = $container->make(BotScoreCalculator::class);
            self::assertInstanceOf(DeviceFingerprintService::class, $container->make(DeviceFingerprintService::class));
            $decision = $calculator->calculate($signals);
            self::assertSame(100, $decision->score);
            self::assertSame(0, $decision->enforcementScore);
            self::assertSame(BotDecision::Block, $decision->assessedDecision);
            self::assertSame(BotDecision::Allow, $decision->decision);
        } finally {
            foreach ($saved as $key => $value) {
                $env->clear($key);
                if ($value !== null) {
                    $env->set($key, $value);
                }
            }
        }
    }
}
