<?php

declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Services\Bot\Contracts\BotSignal;
use App\Services\Bot\DTOs\BotDecisionData;
use App\Services\Bot\DTOs\SignalResult;
use App\Services\Bot\Enums\BotDecision;
use Illuminate\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BotContractsTest extends TestCase
{
    public function test_signal_contract_and_readonly_data(): void
    {
        $signal = new class implements BotSignal
        {
            public function score(Request $request): SignalResult
            {
                return new SignalResult('user_agent', $request->userAgent() === null ? 100 : 0, ['UA_CHECK']);
            }
        };
        $request = Request::create('/');
        $request->headers->remove('User-Agent');
        self::assertSame(100, $signal->score($request)->strength);
        $request->headers->set('User-Agent', 'Browser/1.0');
        self::assertSame(0, $signal->score($request)->strength);
        self::assertTrue((new \ReflectionClass(SignalResult::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(BotDecisionData::class))->isReadOnly());
    }

    public function test_decision_serialization(): void
    {
        $data = new BotDecisionData(90, BotDecision::Allow, ['OBSERVED_RISK'], BotDecision::Block, 0, 'log');
        self::assertSame([
            'score' => 90,
            'decision' => 'allow',
            'reasons' => ['OBSERVED_RISK'],
            'assessed_decision' => 'block',
            'enforcement_score' => 0,
            'mode' => 'log',
        ], $data->toArray());
        self::assertSame(['allow', 'challenge', 'block'], array_column(BotDecision::cases(), 'value'));
    }

    public static function invalidSignals(): array
    {
        return [
            ['', 10, []], ['a.b', 10, []], ['a', -1, []], ['a', 101, []],
            ['a', 10, ['email@example.org']], ['a', 10, [123]],
            ['a', 10, ['key' => 'CODE']], ['a', 10, array_fill(0, 17, 'CODE')],
        ];
    }

    #[DataProvider('invalidSignals')]
    public function test_invalid_signal_data_rejected(string $name, int $strength, array $reasons): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SignalResult($name, $strength, $reasons);
    }

    public static function invalidDecisions(): array
    {
        return [
            [-1, BotDecision::Allow, [], 0, 'log'],
            [101, BotDecision::Allow, [], 0, 'log'],
            [20, BotDecision::Allow, [], 21, 'log'],
            [20, BotDecision::Allow, [], -1, 'log'],
            [90, BotDecision::Block, [], 90, 'log'],
            [90, BotDecision::Challenge, [], 90, 'log'],
            [20, BotDecision::Allow, [], 0, 'invalid'],
            [20, BotDecision::Allow, ['key' => 'CODE'], 0, 'log'],
            [20, BotDecision::Allow, ['192.0.2.1'], 0, 'log'],
        ];
    }

    #[DataProvider('invalidDecisions')]
    public function test_invalid_decision_data_rejected(int $score, BotDecision $decision, array $reasons, int $enforcementScore, string $mode): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BotDecisionData($score, $decision, $reasons, BotDecision::Allow, $enforcementScore, $mode);
    }
}
