<?php

declare(strict_types=1);

namespace Tests\Unit\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Enums\BetFundingSource;
use App\Exceptions\LotteryGameException;
use App\Services\LotteryGames\PayoutCalculator;
use Tests\TestCase;

final class PayoutCalculatorTest extends TestCase
{
    private function calculator(): PayoutCalculator
    {
        return app(PayoutCalculator::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function game(array $overrides = []): GameDefinition
    {
        return GameDefinition::fromConfig('speed_88', array_replace_recursive([
            'code' => 'speed_88',
            'name' => 'Speed 88',
            'digits' => 3,
            'timezone' => 'Asia/Bangkok',
            'draw_cron' => ['0 21 * * *'],
            'cutoff_seconds' => 300,
            'bet_types' => [
                '3d_direct' => [
                    'selection_digits' => 3,
                    'match_mode' => 'exact',
                    'payout_multiplier' => '900',
                    'market_key' => '3d_direct',
                ],
                '2d_direct' => [
                    'selection_digits' => 2,
                    'match_mode' => 'suffix',
                    'payout_multiplier' => '90.5',
                    'market_key' => '2d_top',
                ],
            ],
            'min_stake' => '1.00',
            'max_stake' => '1000.00',
            'max_liability_per_draw' => '100000.00',
            'max_liability_per_number' => '10000.00',
            'rules_status' => 'verified',
            'settlement_market_key' => '3d_direct',
            'publication_bridge' => ['first_prize' => 'all', 'bottom_two' => 'last:2'],
            'result_source' => ['driver' => 'official'],
        ], $overrides));
    }

    public function test_a_stake_times_a_whole_multiplier_is_exact(): void
    {
        $this->assertSame('9000.00', $this->calculator()->potentialPayout($this->game(), '3d_direct', '10.00'));
    }

    public function test_a_fractional_multiplier_rounds_half_up_not_to_float(): void
    {
        // 10.00 × 90.5 = 905.00 exactly; 3.33 × 90.5 = 301.365 → 301.37.
        $this->assertSame('905.00', $this->calculator()->potentialPayout($this->game(), '2d_direct', '10.00'));
        $this->assertSame('301.37', $this->calculator()->potentialPayout($this->game(), '2d_direct', '3.33'));
    }

    public function test_rounding_is_half_up_and_never_a_float_cast(): void
    {
        $calculator = $this->calculator();

        $this->assertSame('0.05', $calculator->roundMoney('0.045'));
        $this->assertSame('2.68', $calculator->roundMoney('2.675'));
        $this->assertSame('1.00', $calculator->roundMoney('0.995'));
        $this->assertSame('-1.01', $calculator->roundMoney('-1.005'));
        $this->assertSame('0.00', $calculator->roundMoney('not-a-number'));
    }

    public function test_a_free_bet_does_not_return_the_stake_by_default(): void
    {
        config()->set('lottery_games.funding.free_bet_returns_stake', false);

        $calculator = $this->calculator();
        $game = $this->game();

        // Multiplier 900 minus the returned stake = 899 paid on top of a stake
        // the player never paid.
        $this->assertSame('899', $calculator->effectiveMultiplier('900', BetFundingSource::FreeBet));
        $this->assertSame('8990.00', $calculator->potentialPayout($game, '3d_direct', '10.00', BetFundingSource::FreeBet));

        // Real money is untouched by the policy.
        $this->assertSame('9000.00', $calculator->potentialPayout($game, '3d_direct', '10.00', BetFundingSource::Real));
    }

    public function test_the_free_bet_policy_is_read_from_configuration_not_assumed(): void
    {
        config()->set('lottery_games.funding.free_bet_returns_stake', true);

        $calculator = $this->calculator();

        $this->assertSame('900', $calculator->effectiveMultiplier('900', BetFundingSource::FreeBet));
        $this->assertSame('9000.00', $calculator->potentialPayout($this->game(), '3d_direct', '10.00', BetFundingSource::FreeBet));
    }

    public function test_a_multiplier_below_one_never_produces_a_negative_payout(): void
    {
        config()->set('lottery_games.funding.free_bet_returns_stake', false);

        // A 1.0x market returns exactly the stake, so a free bet on it pays 0 —
        // not -0.00 and never a negative credit.
        $this->assertSame('0', $this->calculator()->effectiveMultiplier('1', BetFundingSource::FreeBet));
    }

    public function test_an_unverified_multiplier_is_not_paid(): void
    {
        $game = $this->game(['bet_types' => ['3d_direct' => [
            'selection_digits' => 3,
            'match_mode' => 'exact',
            'payout_multiplier' => 'REQUIRED_INPUT',
            'market_key' => '3d_direct',
        ]]]);

        $this->expectException(LotteryGameException::class);

        try {
            $this->calculator()->potentialPayout($game, '3d_direct', '10.00');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_not_configured', $e->errorCode());

            throw $e;
        }
    }

    public function test_totals_are_the_exact_sum_of_the_lines(): void
    {
        $this->assertSame('9905.00', $this->calculator()->totalPotentialPayout([
            ['stake' => '10.00', 'multiplier' => '900'],
            ['stake' => '10.00', 'multiplier' => '90.5'],
        ]));

        $this->assertSame('0.00', $this->calculator()->totalPotentialPayout([]));
    }
}
