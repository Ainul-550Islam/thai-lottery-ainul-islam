<?php

declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Services\Bot\BotDetectionService;
use App\Services\Bot\BotScoreCalculator;
use App\Services\Bot\Contracts\BotSignal;
use App\Services\Bot\DTOs\SignalResult;
use App\Services\Bot\Enums\BotDecision;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class BotDetectionServiceTest extends TestCase
{
    private function config(string $mode = 'log'): Repository
    {
        return new Repository(['bot' => ['enabled' => true, 'mode' => $mode, 'signals' => [
            'a' => ['enabled' => true, 'mode' => 'enforce', 'weight' => 100],
        ]]]);
    }

    private function engine(Repository $config, array $signals, ?LoggerInterface $logger = null): BotDetectionService
    {
        return new BotDetectionService($config, new BotScoreCalculator($config), $logger ?? new NullLogger, $signals);
    }

    public function test_log_assessment_memoization_and_safe_logging(): void
    {
        $signal = $this->createMock(BotSignal::class);
        $signal->expects(self::once())->method('score')->willReturn(new SignalResult('a', 100, ['TEST_RISK']));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('bot.assessment', self::callback(function (array $context): bool {
            self::assertSame('allow', $context['decision']);
            self::assertSame('block', $context['assessed_decision']);
            self::assertSame([], $context['unavailable_signals']);
            self::assertArrayNotHasKey('ip', $context);
            self::assertArrayNotHasKey('fingerprint', $context);

            return true;
        }));
        $engine = $this->engine($this->config(), ['a' => $signal], $logger);
        $request = Request::create('/');
        $first = $engine->detect($request);
        self::assertSame($first, $engine->detect($request));
        self::assertSame(BotDecision::Allow, $first->decision);
    }

    public function test_new_request_is_evaluated_independently(): void
    {
        $signal = $this->createMock(BotSignal::class);
        $signal->expects(self::exactly(2))->method('score')->willReturn(new SignalResult('a', 0));
        $engine = $this->engine($this->config(), ['a' => $signal]);
        $engine->detect(Request::create('/'));
        $engine->detect(Request::create('/'));
    }

    public function test_disabled_global_or_signal_has_no_execution_side_effects(): void
    {
        $signal = $this->createMock(BotSignal::class);
        $signal->expects(self::never())->method('score');
        $config = $this->config();
        $config->set('bot.enabled', false);
        self::assertSame(0, $this->engine($config, ['a' => $signal])->detect(Request::create('/'))->score);
        $config->set('bot.enabled', true);
        $config->set('bot.signals.a.enabled', false);
        self::assertSame(0, $this->engine($config, [])->detect(Request::create('/'))->score);
    }

    public function test_global_and_signal_mode_both_required_to_enforce(): void
    {
        $signal = $this->createMock(BotSignal::class);
        $signal->method('score')->willReturn(new SignalResult('a', 100));
        $config = $this->config('enforce');
        self::assertSame(BotDecision::Block, $this->engine($config, ['a' => $signal])->detect(Request::create('/'))->decision);
        $config->set('bot.signals.a.mode', 'log');
        self::assertSame(BotDecision::Allow, $this->engine($config, ['a' => $signal])->detect(Request::create('/'))->decision);
    }

    public function test_missing_enabled_signal_is_configuration_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->engine($this->config(), [])->detect(Request::create('/'));
    }

    public function test_bad_weight_rejected_before_stateful_signal(): void
    {
        $signal = $this->createMock(BotSignal::class);
        $signal->expects(self::never())->method('score');
        $config = $this->config();
        $config->set('bot.signals.a.weight', -1);
        $this->expectException(InvalidArgumentException::class);
        $this->engine($config, ['a' => $signal])->detect(Request::create('/'));
    }

    public function test_mismatched_signal_identity_rejected(): void
    {
        $signal = $this->createMock(BotSignal::class);
        $signal->method('score')->willReturn(new SignalResult('b', 100));
        $this->expectException(LogicException::class);
        $this->engine($this->config(), ['a' => $signal])->detect(Request::create('/'));
    }

    public function test_observation_failure_is_visible_without_blocking_or_leaking_exception(): void
    {
        $signal = $this->createMock(BotSignal::class);
        $signal->method('score')->willThrowException(new \RuntimeException('sensitive-query-password'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('bot.assessment', self::callback(function (array $context): bool {
            self::assertStringNotContainsString('sensitive-query-password', json_encode($context));
            self::assertSame(['a'], $context['unavailable_signals']);

            return true;
        }));
        $result = $this->engine($this->config(), ['a' => $signal], $logger)->detect(Request::create('/'));
        self::assertSame(BotDecision::Allow, $result->decision);
        self::assertSame(0, $result->score);
        self::assertContains('BOT_SIGNAL_UNAVAILABLE', $result->reasons);
    }

    public function test_enforcement_failure_does_not_return_false_allow(): void
    {
        $signal = $this->createMock(BotSignal::class);
        $signal->method('score')->willThrowException(new \RuntimeException('database unavailable'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('enforcement assessment aborted');
        $this->engine($this->config('enforce'), ['a' => $signal])->detect(Request::create('/'));
    }

    public function test_log_only_signal_failure_does_not_abort_other_enforcement(): void
    {
        $signal = $this->createMock(BotSignal::class);
        $signal->method('score')->willThrowException(new \RuntimeException('cache unavailable'));
        $config = $this->config('enforce');
        $config->set('bot.signals.a.mode', 'log');
        $result = $this->engine($config, ['a' => $signal])->detect(Request::create('/'));
        self::assertSame(BotDecision::Allow, $result->decision);
        self::assertContains('BOT_SIGNAL_UNAVAILABLE', $result->reasons);
    }

    public function test_log_transport_failure_remains_observation_only(): void
    {
        $signal = $this->createMock(BotSignal::class);
        $signal->method('score')->willReturn(new SignalResult('a', 100));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willThrowException(new \RuntimeException('disk full'));
        $result = $this->engine($this->config(), ['a' => $signal], $logger)->detect(Request::create('/'));
        self::assertSame(BotDecision::Allow, $result->decision);
        self::assertContains('BOT_OBSERVABILITY_UNAVAILABLE', $result->reasons);
    }

    public function test_invalid_signal_registry_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->engine($this->config(), ['a' => new \stdClass]);
    }
}
