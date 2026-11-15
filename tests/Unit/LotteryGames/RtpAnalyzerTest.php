<?php

declare(strict_types=1);

namespace Tests\Unit\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Services\LotteryGames\RtpAnalyzer;
use Tests\TestCase;

/**
 * Hand-computed RTP, so a payout typo is caught before it becomes a liability.
 */
final class RtpAnalyzerTest extends TestCase
{
    private RtpAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new RtpAnalyzer;
    }

    public function test_exact_3_digit_market_at_900_pays_back_90_percent(): void
    {
        $game = $this->game([
            '3d_direct' => ['selection_digits' => 3, 'match_mode' => 'exact', 'payout_multiplier' => '900'],
        ], digits: 3);

        $result = $this->analyzer->analyze($game, '0.98');

        // 1/1000 * 900 = 0.9
        $this->assertSame('0.90000000', $result['bet_types']['3d_direct']['rtp']);
        $this->assertTrue($result['within_ceiling']);
    }

    public function test_suffix_2_digit_market_at_90_pays_back_90_percent(): void
    {
        $game = $this->game([
            '2d_direct' => ['selection_digits' => 2, 'match_mode' => 'suffix', 'payout_multiplier' => '90'],
        ], digits: 2);

        $result = $this->analyzer->analyze($game, '0.98');

        $this->assertSame('0.90000000', $result['bet_types']['2d_direct']['rtp']);
    }

    public function test_any_order_uses_the_best_case_arrangement_count(): void
    {
        // 4 digits any order, draw length 4: best case 24/10000 = 0.0024
        // x 400 = 0.96 -> inside a 0.98 ceiling
        $game = $this->game([
            '4d_any_order' => ['selection_digits' => 4, 'match_mode' => 'any_order', 'payout_multiplier' => '400'],
        ], digits: 4);

        $result = $this->analyzer->analyze($game, '0.98');

        $this->assertSame('0.00240000', $result['bet_types']['4d_any_order']['probability']);
        $this->assertSame('0.96000000', $result['bet_types']['4d_any_order']['rtp']);
        $this->assertTrue($result['within_ceiling']);
    }

    public function test_a_multiplier_above_the_ceiling_is_reported_as_a_breach(): void
    {
        // 1/100 * 120 = 1.2 -> breaches a 0.98 ceiling
        $game = $this->game([
            '2d_direct' => ['selection_digits' => 2, 'match_mode' => 'suffix', 'payout_multiplier' => '120'],
        ], digits: 2);

        $result = $this->analyzer->analyze($game, '0.98');

        $this->assertFalse($result['within_ceiling']);
        $this->assertNotEmpty($result['breaches']);
        $this->assertStringContainsString('2d_direct', $result['breaches'][0]);
    }

    /**
     * @param  array<string, array{selection_digits: int, match_mode: string, payout_multiplier: string}>  $betTypes
     */
    private function game(array $betTypes, int $digits): GameDefinition
    {
        $rules = [];

        foreach ($betTypes as $betType => $rule) {
            $rules[$betType] = $rule + ['market_key' => '2d_top'];
        }

        return GameDefinition::fromConfig('test_game', [
            'code' => 'test_game',
            'name' => 'Test Game',
            'digits' => $digits,
            'timezone' => 'Asia/Bangkok',
            'draw_cron' => ['0 21 * * *'],
            'cutoff_seconds' => 300,
            'bet_types' => $rules,
            'min_stake' => '1',
            'max_stake' => '1000',
            'max_liability_per_draw' => '100000',
            'max_liability_per_number' => '10000',
            'rules_status' => 'unverified',
            'settlement_market_key' => '2d_top',
            'publication_bridge' => ['first_prize' => 'all', 'bottom_two' => 'last:2'],
            'result_source' => [
                'base_url' => 'https://example.test',
                'latest_path' => '/latest',
                'draw_no_field' => 'draw_no',
                'digits_field' => 'digits',
            ],
        ]);
    }
}
