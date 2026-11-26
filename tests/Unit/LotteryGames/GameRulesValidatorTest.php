<?php

declare(strict_types=1);

namespace Tests\Unit\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Services\LotteryGames\GameRulesValidator;
use Tests\TestCase;

final class GameRulesValidatorTest extends TestCase
{
    private GameRulesValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new GameRulesValidator;
    }

    public function test_the_shipped_speed_88_ruleset_is_incomplete(): void
    {
        $game = GameDefinition::fromConfig('speed_88', (array) config('lottery_games.games.speed_88'));

        $report = $this->validator->validate($game);

        $this->assertFalse($report['valid']);
        $this->assertContains('digits', $report['missing']);
        $this->assertContains('bet_types.4d_direct.payout_multiplier', $report['missing']);
        $this->assertContains('settlement_market_key', $report['missing']);
    }

    public function test_a_complete_ruleset_validates(): void
    {
        $report = $this->validator->validate($this->complete());

        $this->assertTrue($report['valid'], implode('; ', $report['problems']));
        $this->assertSame([], $report['missing']);
    }

    public function test_a_missing_payout_multiplier_is_reported(): void
    {
        $config = $this->completeConfig();
        $config['bet_types']['2d_direct']['payout_multiplier'] = 'REQUIRED_INPUT';

        $report = $this->validator->validate(GameDefinition::fromConfig('test_game', $config));

        $this->assertFalse($report['valid']);
        $this->assertContains('bet_types.2d_direct.payout_multiplier', $report['missing']);
    }

    public function test_a_cutoff_longer_than_the_cadence_is_reported(): void
    {
        $config = $this->completeConfig();
        // hourly draw, 90-minute cutoff: betting would close before it opened
        $config['draw_cron'] = ['0 * * * *'];
        $config['cutoff_seconds'] = 9000;

        $report = $this->validator->validate(GameDefinition::fromConfig('test_game', $config));

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString('cadence', implode(' ', $report['problems']));
    }

    public function test_a_digit_count_that_disagrees_with_the_markets_is_reported(): void
    {
        $config = $this->completeConfig();
        $config['digits'] = 4; // the only market is 2 digits

        $report = $this->validator->validate(GameDefinition::fromConfig('test_game', $config));

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString('disagree', implode(' ', $report['problems']));
    }

    public function test_a_non_numeric_multiplier_is_reported(): void
    {
        $config = $this->completeConfig();
        $config['bet_types']['2d_direct']['payout_multiplier'] = 'ninety';

        $report = $this->validator->validate(GameDefinition::fromConfig('test_game', $config));

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString('numeric payout_multiplier', implode(' ', $report['problems']));
    }

    private function complete(): GameDefinition
    {
        return GameDefinition::fromConfig('test_game', $this->completeConfig());
    }

    /**
     * @return array<string, mixed>
     */
    private function completeConfig(): array
    {
        return [
            'code' => 'test_game',
            'name' => 'Test Game',
            'digits' => 2,
            'timezone' => 'Asia/Bangkok',
            'draw_cron' => ['0 21 * * *'],
            'cutoff_seconds' => 300,
            'bet_types' => [
                '2d_direct' => [
                    'selection_digits' => 2,
                    'match_mode' => 'suffix',
                    'payout_multiplier' => '90',
                    'market_key' => '2d_top',
                ],
            ],
            'min_stake' => '1',
            'max_stake' => '1000',
            'max_liability_per_draw' => '100000',
            'max_liability_per_number' => '10000',
            'rules_status' => 'verified',
            'settlement_market_key' => '2d_top',
            'publication_bridge' => ['first_prize' => 'all', 'bottom_two' => 'last:2'],
            'result_source' => [
                'base_url' => 'https://example.test',
                'latest_path' => '/latest',
                'by_draw_no_path' => '/draw/{draw_no}',
                'draw_no_field' => 'draw_no',
                'digits_field' => 'digits',
                'drawn_at_field' => 'drawn_at',
                'schema_version' => '1',
            ],
        ];
    }
}
