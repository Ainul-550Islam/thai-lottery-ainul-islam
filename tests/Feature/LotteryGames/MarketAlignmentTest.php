<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\Services\LotteryGames\GameRulesValidator;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * The multiplier a player is QUOTED must be the multiplier Batch 1 will PAY.
 *
 * The lane quotes, prices RTP and reserves liability from lottery_games.bet_types, while
 * Batch 1's settlement pays from lottery.markets.<market>.payout_multiplier. The validator
 * is the only place the two meet, so these cases pin each branch of that comparison.
 *
 * The reference numbers (900x for 3d_direct, 90x for 2d_bottom) are read back from the
 * live Batch-1 market table in each test rather than restated, so a change to that table
 * fails an assertion that names it instead of silently shifting what is being compared.
 */
final class MarketAlignmentTest extends GameLaneTestCase
{
    private function report(array $betTypes): array
    {
        $definition = $this->declareLaneGame('lane_game', ['bet_types' => $betTypes]);

        return (new GameRulesValidator)->validate($definition);
    }

    private function exactThreeDigit(string $multiplier): array
    {
        return [
            '3d_direct' => [
                'selection_digits' => 3,
                'match_mode' => 'exact',
                'payout_multiplier' => $multiplier,
                'market_key' => '3d_direct',
            ],
        ];
    }

    public function test_an_exact_bet_quoting_the_markets_own_multiplier_raises_nothing(): void
    {
        $paid = (string) config('lottery.markets.3d_direct.payout_multiplier');

        $report = $this->report($this->exactThreeDigit($paid));

        $this->assertSame([], array_values(array_filter(
            $report['problems'],
            static fn (string $problem): bool => str_contains($problem, 'settles through market'),
        )));
    }

    public function test_an_exact_bet_quoting_a_different_multiplier_is_a_problem(): void
    {
        $paid = (string) config('lottery.markets.3d_direct.payout_multiplier');
        $quoted = bcadd($paid, '100', 0);

        $report = $this->report($this->exactThreeDigit($quoted));

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString(
            sprintf('quotes %sx but settles through market "3d_direct" which pays %sx', $quoted, $paid),
            implode(' | ', $report['problems']),
        );
    }

    public function test_a_non_exact_bet_on_a_market_with_another_multiplier_is_only_a_warning(): void
    {
        $report = $this->report([
            '3d_any_order' => [
                'selection_digits' => 3,
                'match_mode' => 'any_order',
                'payout_multiplier' => '1',
                'market_key' => '3d_direct',
            ],
        ]);

        $this->assertStringContainsString('3d_any_order', implode(' | ', $report['warnings']));
        $this->assertSame([], array_values(array_filter(
            $report['problems'],
            static fn (string $problem): bool => str_contains($problem, 'settles through market'),
        )));
    }

    public function test_a_width_mismatch_is_a_warning_because_placement_already_refuses_it(): void
    {
        $report = $this->report([
            '4d_direct' => [
                'selection_digits' => 4,
                'match_mode' => 'exact',
                'payout_multiplier' => '6000',
                'market_key' => '3d_direct',
            ],
        ]);

        $this->assertStringContainsString('placement will refuse it', implode(' | ', $report['warnings']));
        $this->assertSame([], array_values(array_filter(
            $report['problems'],
            static fn (string $problem): bool => str_contains($problem, 'settles through market'),
        )));
    }
}
