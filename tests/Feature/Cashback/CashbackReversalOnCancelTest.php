<?php

declare(strict_types=1);

namespace Tests\Feature\Cashback;

use App\DTOs\LotteryGames\DigitBetSelection;
use App\Events\BetCancelled;
use App\Models\Bet;
use App\Models\GameBetAttribution;
use App\Services\LotteryGames\GameBetPlacementService;
use App\Services\LotteryGames\LiabilityExposureService;
use Tests\Support\LotteryGames\FakeCashbackService;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * What a cancelled digit bet must undo: its reserved liability, and its cashback accrual.
 *
 * WHY BOTH, IN ONE SUITE
 * They are the same event seen from two ledgers. The liability counter is the operator's
 * risk ledger; the cashback accrual is the player's. A cancellation that releases one and
 * not the other is not half-right — it is wrong in whichever direction nobody is looking:
 * reserved exposure stranded on a bet that no longer exists makes the risk screen overstate
 * (and eventually refuse legitimate bets), while an unreversed accrual pays a player cashback
 * on a bet they got their money back for.
 *
 * WHY A CANCELLATION IS DISPATCHED RATHER THAN TRIGGERED THROUGH BATCH 1
 * Batch 1 owns its cancellation path and does not know this lane reserved anything. Batch 2
 * therefore adds an EVENT (`App\Events\BetCancelled`) and two listeners, registered in the
 * application's event map — discovery is disabled for this codebase, so a listener that is
 * not in that map never runs, which is precisely the bug that would leave liability stranded
 * on every cancelled digit bet. These tests dispatch the real event, so they prove the
 * registration as well as the behaviour.
 *
 * WHY THE LISTENERS SWALLOW THEIR OWN FAILURES
 * The cancellation has already committed: the player's money is back and the bet is marked
 * cancelled. Throwing here would turn a successful request into a 500 and roll nothing back.
 * A failure is logged with the identifiers an operator needs. The tests below pin that: a
 * broken cashback lane must not stop the liability release.
 *
 * THE BASIS EXCLUSION IS THE SUBTLE ONE
 * Bonus- and free-bet-funded stakes are outside the cashback basis, so cancelling them must
 * NOT ask for a reversal. Asking anyway credits the player twice — once by the reversal,
 * once by the fact that the stake was never really theirs.
 */
final class CashbackReversalOnCancelTest extends GameLaneTestCase
{
    private function placement(): GameBetPlacementService
    {
        return app(GameBetPlacementService::class);
    }

    private function exposure(): LiabilityExposureService
    {
        return app(LiabilityExposureService::class);
    }

    /**
     * @return array{draw: \App\Models\Draw, user: \App\Models\User, wallet: \App\Models\Wallet, cashback: FakeCashbackService}
     */
    private function ready(): array
    {
        ['draw' => $draw] = $this->openLane('lane_game');
        $player = $this->player('1000.00');
        $this->limitFor($draw, '3d_direct', '123');

        $cashback = new FakeCashbackService();
        $this->app->instance(FakeCashbackService::class, $cashback);
        config()->set('lottery_games.funding.cashback_service', FakeCashbackService::class);

        return ['draw' => $draw, 'cashback' => $cashback, ...$player];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function placeBet(int $userId, int $drawId, string $key, ?string $voucherId = null): array
    {
        return $this->placement()->place(
            userId: $userId,
            selection: new DigitBetSelection('lane_game', '3d_direct', '123', '10.00'),
            drawId: $drawId,
            idempotencyKey: $this->key($key),
            voucherId: $voucherId,
            fundingSource: $voucherId === null ? null : 'free_bet',
        );
    }

    // =========================================================================
    // The liability release
    // =========================================================================

    public function test_cancelling_a_digit_bet_releases_its_reserved_liability(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        $receipt = $this->placeBet((int) $user->getKey(), (int) $draw->getKey(), 'cancel-release');

        $this->assertSame('9000.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));

        $bet = Bet::query()->findOrFail($receipt['bet_id']);
        BetCancelled::dispatch($bet, 'cancelled by the operator');

        $this->assertSame('0.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));
        $this->assertSame('0.00', $this->exposure()->reservedPotentialPayout($draw->getKey(), 'lane_game'));
    }

    public function test_the_release_uses_the_amount_frozen_at_placement_not_the_current_multiplier(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        $receipt = $this->placeBet((int) $user->getKey(), (int) $draw->getKey(), 'cancel-frozen');

        // The operator halves the multiplier after the bet exists. The reservation to give
        // back is the one that was made, not the one today's configuration implies.
        config()->set('lottery_games.games.lane_game.bet_types.3d_direct.payout_multiplier', '450');
        $this->refreshGameRegistry();

        BetCancelled::dispatch(Bet::query()->findOrFail($receipt['bet_id']), 'draw cancelled');

        $this->assertSame('0.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));
    }

    public function test_a_second_cancellation_of_the_same_bet_cannot_drive_the_counter_negative(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        $receipt = $this->placeBet((int) $user->getKey(), (int) $draw->getKey(), 'cancel-twice');
        $bet = Bet::query()->findOrFail($receipt['bet_id']);

        BetCancelled::dispatch($bet, 'first');
        BetCancelled::dispatch($bet, 'replayed delivery');

        $this->assertSame('0.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));
        $this->assertNull($this->exposure()->row($draw->getKey(), 'lane_game', '123', '3d_direct'));
    }

    public function test_a_cancelled_bet_leaves_its_neighbour_untouched(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();
        $this->limitFor($draw, '3d_direct', '456');

        $first = $this->placeBet((int) $user->getKey(), (int) $draw->getKey(), 'cancel-a');

        $second = $this->placement()->place(
            (int) $user->getKey(),
            new DigitBetSelection('lane_game', '3d_direct', '456', '10.00'),
            (int) $draw->getKey(),
            $this->key('cancel-b'),
        );

        BetCancelled::dispatch(Bet::query()->findOrFail($first['bet_id']), 'only this one');

        $this->assertSame('0.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));
        $this->assertSame('9000.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '456', '3d_direct'));
        $this->assertSame('9000.00', $this->exposure()->reservedPotentialPayout($draw->getKey(), 'lane_game'));
        $this->assertNotNull(GameBetAttribution::query()->find($second['attribution_id']));
    }

    // =========================================================================
    // The cashback reversal
    // =========================================================================

    public function test_a_real_money_bet_asks_the_cashback_lane_to_reverse(): void
    {
        ['draw' => $draw, 'user' => $user, 'cashback' => $cashback] = $this->ready();

        $receipt = $this->placeBet((int) $user->getKey(), (int) $draw->getKey(), 'cashback-real');

        BetCancelled::dispatch(Bet::query()->findOrFail($receipt['bet_id']), 'draw cancelled by the operator');

        $this->assertCount(1, $cashback->reversals);
        $this->assertSame((int) $user->getKey(), $cashback->reversals[0]['user_id']);
        $this->assertSame($receipt['bet_id'], $cashback->reversals[0]['bet_id']);
        $this->assertSame('draw cancelled by the operator', $cashback->reversals[0]['reason']);
    }

    public function test_a_bet_with_no_cashback_lane_installed_is_released_without_an_error(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        // No Batch-1 cashback service on this deployment: the reversal is skipped and
        // logged, and the liability release still happens.
        config()->set('lottery_games.funding.cashback_service', null);

        $receipt = $this->placeBet((int) $user->getKey(), (int) $draw->getKey(), 'cashback-absent');

        BetCancelled::dispatch(Bet::query()->findOrFail($receipt['bet_id']), 'no cashback lane');

        $this->assertSame('0.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));
    }

    public function test_a_broken_cashback_lane_does_not_stop_the_liability_release(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        // A listener that throws must not turn a committed cancellation into a 500, and
        // must not leave the operator carrying liability for a bet that is gone.
        $this->app->instance(FakeCashbackService::class, new class
        {
            public function reverse(int $userId, int $betId, string $reason): void
            {
                throw new \RuntimeException('the cashback lane is down');
            }
        });

        $receipt = $this->placeBet((int) $user->getKey(), (int) $draw->getKey(), 'cashback-broken');

        BetCancelled::dispatch(Bet::query()->findOrFail($receipt['bet_id']), 'cashback lane is down');

        $this->assertSame('0.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));
    }

    public function test_a_bet_that_belongs_to_no_digit_game_is_left_alone(): void
    {
        ['draw' => $draw, 'cashback' => $cashback] = $this->ready();

        // A plain Batch-1 bet: no game_code, no attribution, nothing reserved.
        $player = $this->player('500.00');
        $bet = Bet::factory()->create([
            'user_id' => $player['user']->getKey(),
            'draw_id' => $draw->getKey(),
            'stake_amount' => '20.00',
        ]);

        BetCancelled::dispatch($bet, 'an ordinary cancellation');

        // The cashback lane still hears it — cashback is Batch 1's rule, not the digit
        // lane's — but nothing digit-game specific was touched.
        $this->assertCount(1, $cashback->reversals);
        $this->assertSame('0.00', $this->exposure()->reservedPotentialPayout($draw->getKey(), 'lane_game'));
    }
}
