<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\Bot\BotScoreCalculator;
use App\Services\Bot\DTOs\SignalResult;
use App\Services\Bot\Enums\BotDecision;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

class BotScoreCalculatorTest extends TestCase
{
    private function makeCalculator(string $mode = 'enforce'): BotScoreCalculator
    {
        return new BotScoreCalculator(new Repository([
            'bot' => [
                'enabled' => true,
                'mode' => $mode,
                'thresholds' => [
                    'challenge' => 40,
                    'block' => 80,
                ],
                'signals' => [
                    'user_agent' => ['enabled' => true, 'weight' => 70, 'mode' => 'enforce'],
                    'header_consistency' => ['enabled' => true, 'weight' => 80, 'mode' => 'enforce'],
                ],
            ],
        ]));
    }

    public function test_evaluates_clean_request_as_allow(): void
    {
        $decision = $this->makeCalculator('enforce')->calculate([
            new SignalResult('user_agent', 0),
            new SignalResult('header_consistency', 0),
        ]);

        $this->assertSame(0, $decision->score);
        $this->assertSame(BotDecision::Allow, $decision->decision);
        $this->assertEmpty($decision->reasons);
    }

    public function test_clamps_cumulative_score_at_100(): void
    {
        $decision = $this->makeCalculator('enforce')->calculate([
            new SignalResult('user_agent', 100, ['TEST_HIGH_1']),
            new SignalResult('header_consistency', 100, ['TEST_HIGH_2']),
        ]);

        $this->assertSame(100, $decision->score);
        $this->assertSame(BotDecision::Block, $decision->decision);
        $this->assertCount(2, $decision->reasons);
    }

    public function test_log_mode_never_blocks_or_challenges(): void
    {
        $decision = $this->makeCalculator('log')->calculate([
            new SignalResult('header_consistency', 100, ['CRITICAL_BOT']),
        ]);

        $this->assertSame(80, $decision->score);
        $this->assertSame(BotDecision::Allow, $decision->decision);
        $this->assertSame('log', $decision->mode);
    }
}
