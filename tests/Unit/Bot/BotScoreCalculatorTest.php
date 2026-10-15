<?php

declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Services\Bot\BotScoreCalculator;
use App\Services\Bot\DTOs\SignalResult;
use App\Services\Bot\Enums\BotDecision;
use Illuminate\Config\Repository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BotScoreCalculatorTest extends TestCase
{
    private function config(string $mode = 'enforce'): Repository
    {
        return new Repository(['bot' => [
            'enabled' => true,
            'mode' => $mode,
            'thresholds' => ['challenge' => 40, 'block' => 80],
            'signals' => [
                'a' => ['enabled' => true, 'mode' => 'enforce', 'weight' => 100],
                'b' => ['enabled' => true, 'mode' => 'enforce', 'weight' => 100],
            ],
        ]]);
    }

    public static function boundaries(): array
    {
        return [[0, 'allow'], [39, 'allow'], [40, 'challenge'], [79, 'challenge'], [80, 'block'], [100, 'block']];
    }

    #[DataProvider('boundaries')]
    public function test_threshold_boundaries(int $score, string $decision): void
    {
        $result = (new BotScoreCalculator($this->config()))->calculate([new SignalResult('a', $score, ['TEST_SIGNAL'])]);
        self::assertSame($score, $result->score);
        self::assertSame($decision, $result->decision->value);
        self::assertSame($result->decision, $result->assessedDecision);
    }

    public function test_log_mode_records_risk_but_never_challenges_or_blocks(): void
    {
        $result = (new BotScoreCalculator($this->config('log')))->calculate([new SignalResult('a', 100, ['TEST_SIGNAL'])]);
        self::assertSame(100, $result->score);
        self::assertSame(BotDecision::Block, $result->assessedDecision);
        self::assertSame(BotDecision::Allow, $result->decision);
    }

    public function test_signal_log_mode_cannot_leak_into_enforcement_score(): void
    {
        $config = $this->config();
        $config->set('bot.signals.a.mode', 'log');
        $result = (new BotScoreCalculator($config))->calculate([
            new SignalResult('a', 100, ['LOG_SIGNAL']),
            new SignalResult('b', 39, ['ENFORCED_SIGNAL']),
        ]);
        self::assertSame(100, $result->score);
        self::assertSame(39, $result->enforcementScore);
        self::assertSame(BotDecision::Allow, $result->decision);
    }

    public function test_capped_sum_unique_reasons_and_disabled_signal(): void
    {
        $config = $this->config();
        $calculator = new BotScoreCalculator($config);
        $signals = [new SignalResult('a', 70, ['SHARED_REASON']), new SignalResult('b', 80, ['SHARED_REASON'])];
        self::assertSame(100, $calculator->calculate($signals)->score);
        self::assertSame(['SHARED_REASON'], $calculator->calculate($signals)->reasons);
        $config->set('bot.signals.b.enabled', false);
        self::assertSame(70, $calculator->calculate($signals)->score);
    }

    public function test_weighting_rounding_and_empty_results(): void
    {
        $config = $this->config();
        $config->set('bot.signals.a.weight', 25);
        $calculator = new BotScoreCalculator($config);
        self::assertSame(13, $calculator->calculate([new SignalResult('a', 50)])->score);
        self::assertSame(0, $calculator->calculate([])->score);
        self::assertSame([], $calculator->calculate([new SignalResult('a', 0, ['INACTIVE'])])->reasons);
    }

    public function test_global_disable_is_side_effect_free_allow(): void
    {
        $config = $this->config();
        $config->set('bot.enabled', false);
        $result = (new BotScoreCalculator($config))->calculate([new SignalResult('a', 100)]);
        self::assertSame(0, $result->score);
        self::assertSame(BotDecision::Allow, $result->decision);
    }

    public static function invalidConfiguration(): array
    {
        return [
            ['bot.mode', 'invalid'],
            ['bot.enabled', 'true'],
            ['bot.thresholds.challenge', 80],
            ['bot.thresholds.challenge', 0],
            ['bot.thresholds.block', 101],
            ['bot.signals.a.mode', 'invalid'],
            ['bot.signals.a.weight', -1],
            ['bot.signals.a.weight', 101],
            ['bot.signals.a.weight', '20'],
            ['bot.signals.a.enabled', 'false'],
        ];
    }

    #[DataProvider('invalidConfiguration')]
    public function test_invalid_configuration_is_not_silently_accepted(string $key, mixed $value): void
    {
        $config = $this->config();
        $config->set($key, $value);
        $this->expectException(InvalidArgumentException::class);
        (new BotScoreCalculator($config))->calculate([new SignalResult('a', 100)]);
    }

    public function test_duplicate_signals_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BotScoreCalculator($this->config()))->calculate([new SignalResult('a', 20), new SignalResult('a', 20)]);
    }

    public function test_unconfigured_signal_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BotScoreCalculator($this->config()))->calculate([new SignalResult('unknown', 10)]);
    }

    public function test_invalid_signal_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BotScoreCalculator($this->config()))->calculate([new \stdClass]);
    }
}
