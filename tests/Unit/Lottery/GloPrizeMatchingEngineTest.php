<?php

declare(strict_types=1);

namespace Tests\Unit\Lottery;

use App\Enums\LotteryPrizeTier;
use App\Services\Lottery\GloPrizeMatchingEngine;
use PHPUnit\Framework\TestCase;

class GloPrizeMatchingEngineTest extends TestCase
{
    private GloPrizeMatchingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new GloPrizeMatchingEngine();
    }

    public function test_matches_official_first_prize_with_leading_zeros(): void
    {
        $drawResult = [
            'first_prize' => '000123',
            'three_digit_front' => ['111', '222'],
            'three_digit_back' => ['333', '444'],
            'two_digit_bottom' => '55',
        ];

        $res = $this->engine->evaluateEntry('6D_OFFICIAL', '000123', '80.0000', $drawResult);

        $this->assertTrue($res->isWinner);
        $this->assertSame(LotteryPrizeTier::FIRST_PRIZE, $res->prizeTier);
        $this->assertSame('6000000.0000', $res->grossPayoutAmount);
        $this->assertSame('30000.0000', $res->whtTaxAmount); // 0.5% tax
        $this->assertSame('5970000.0000', $res->netPayoutAmount);
    }

    public function test_matches_near_first_prize_boundaries(): void
    {
        $drawResult = [
            'first_prize' => '000000',
            'three_digit_front' => ['123', '456'],
            'three_digit_back' => ['789', '012'],
            'two_digit_bottom' => '99',
        ];

        // 000000 - 1 = 999999 (wrap around)
        $resWrap = $this->engine->evaluateEntry('6D_OFFICIAL', '999999', '80.0000', $drawResult);
        $this->assertTrue($resWrap->isWinner);
        $this->assertSame(LotteryPrizeTier::NEAR_FIRST_PRIZE, $resWrap->prizeTier);

        // 000000 + 1 = 000001
        $resNext = $this->engine->evaluateEntry('6D_OFFICIAL', '000001', '80.0000', $drawResult);
        $this->assertTrue($resNext->isWinner);
        $this->assertSame(LotteryPrizeTier::NEAR_FIRST_PRIZE, $resNext->prizeTier);
    }

    public function test_matches_3d_direct_and_toad_variations(): void
    {
        $drawResult = [
            'first_prize' => '987654', // last 3 are '654'
            'three_digit_front' => ['123', '456'],
            'three_digit_back' => ['789', '012'],
            'two_digit_bottom' => '32',
        ];

        // 3D Direct: Exact match '654' -> 900x multiplier
        $direct = $this->engine->evaluateEntry('3D_DIRECT', '654', '100.0000', $drawResult);
        $this->assertTrue($direct->isWinner);
        $this->assertSame(LotteryPrizeTier::THREE_DIGIT_DIRECT, $direct->prizeTier);
        $this->assertSame('90000.0000', $direct->grossPayoutAmount);

        // 3D Toad: Permutation '456' -> 150x multiplier
        $toad = $this->engine->evaluateEntry('3D_TOAD', '456', '100.0000', $drawResult);
        $this->assertTrue($toad->isWinner);
        $this->assertSame(LotteryPrizeTier::THREE_DIGIT_TOAD, $toad->prizeTier);
        $this->assertSame('15000.0000', $toad->grossPayoutAmount);

        // Non-winner Toad '123'
        $nonToad = $this->engine->evaluateEntry('3D_TOAD', '123', '100.0000', $drawResult);
        $this->assertFalse($nonToad->isWinner);
    }

    public function test_matches_2d_top_and_bottom_variations(): void
    {
        $drawResult = [
            'first_prize' => '123456', // last 2 are '56'
            'three_digit_front' => ['111', '222'],
            'three_digit_back' => ['333', '444'],
            'two_digit_bottom' => '89',
        ];

        // 2D Top '56'
        $top = $this->engine->evaluateEntry('2D_TOP', '56', '10.0000', $drawResult);
        $this->assertTrue($top->isWinner);
        $this->assertSame('900.0000', $top->grossPayoutAmount);

        // 2D Bottom '89'
        $bottom = $this->engine->evaluateEntry('2D_BOTTOM', '89', '20.0000', $drawResult);
        $this->assertTrue($bottom->isWinner);
        $this->assertSame('1800.0000', $bottom->grossPayoutAmount);
    }
}
