<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Enums\BotDecision;
use App\Services\Bot\BotScoreCalculator;
use PHPUnit\Framework\TestCase;

class BotScoreCalculatorTest extends TestCase
{
    private BotScoreCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new BotScoreCalculator();
    }

    public function test_evaluates_clean_request_as_allow(): void
    {
        $signals = [
            'user_agent' => ['triggered' => false, 'score' => 0, 'reason_code' => null],
            'request_velocity' => ['triggered' => false, 'score' => 0, 'reason_code' => null],
        ];

        $decision = $this->calculator->calculateScore($signals, 'enforce');

        $this->assertSame(0, $decision->score);
        $this->assertSame(BotDecision::ALLOW, $decision->decision);
        $this->assertEmpty($decision->reasons);
    }

    public function test_clamps_cumulative_score_at_100(): void
    {
        $signals = [
            'sig1' => ['triggered' => true, 'score' => 70, 'reason_code' => 'TEST_HIGH_1'],
            'sig2' => ['triggered' => true, 'score' => 80, 'reason_code' => 'TEST_HIGH_2'],
        ];

        $decision = $this->calculator->calculateScore($signals, 'enforce');

        $this->assertSame(100, $decision->score);
        $this->assertSame(BotDecision::BLOCK, $decision->decision);
        $this->assertCount(2, $decision->reasons);
    }

    public function test_log_only_mode_never_blocks_or_challenges(): void
    {
        $signals = [
            'sig1' => ['triggered' => true, 'score' => 90, 'reason_code' => 'CRITICAL_BOT'],
        ];

        $decision = $this->calculator->calculateScore($signals, 'log_only');

        $this->assertSame(90, $decision->score);
        $this->assertSame(BotDecision::ALLOW, $decision->decision);
        $this->assertSame('log_only', $decision->operatingMode);
    }
}
