<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\Enums\BetStatus;
use App\Enums\FinancialTransactionType;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\GameBetAttribution;
use App\Models\Payout;
use App\Models\Wallet;
use App\Services\LotteryGames\GameSettlementAdapter;
use Tests\Feature\LotteryGames\Concerns\BuildsSettledDraw;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * Settling once, and only once - the property that decides whether a retry is a retry or a
 * second prize.
 *
 * WHY THIS SUITE IS SEPARATE FROM SettlementTest
 * SettlementTest proves that settlement pays the right person the right amount. That is a
 * question about arithmetic and matching. This one is a question about REPETITION, and in
 * production repetition is not an edge case: a queue worker is restarted mid-job, an
 * operator re-runs a command because the first run's log looked truncated, a scheduler
 * fires twice across a deploy, a support agent clicks settle again because the screen
 * looked stale. Every one of those is the same request arriving twice, and the difference
 * between a platform that survives it and one that does not is whether the second arrival
 * is a no-op.
 *
 * WHAT "ONCE" IS ASSERTED TO MEAN, CONCRETELY
 *   one payout row       the (draw, bet) unique reference makes a second payout impossible
 *                        even if every other guard failed
 *   one ledger credit    the wallet credit carries a deterministic idempotency key, so the
 *                        financial transaction is replayed rather than repeated
 *   one balance delta    the wallet ends where the FIRST settlement left it
 *   a truthful receipt   settled=false, already_settled=true: the platform says "this was
 *                        already done" instead of claiming to have done it again
 *   a stable record      the attribution rows and their frozen potential_payout are not
 *                        rewritten by a replay
 *
 * WHY THE SECOND CALL IS MADE THROUGH THE SAME ADAPTER
 * Because that is the real threat: Batch 1's own settlement service is idempotent, and the
 * only way a caller of this lane can break that is to go around it. Both calls below go
 * through GameSettlementAdapter::settle(), the number and the id path, so what is measured
 * is the seam an operator or a job actually uses.
 *
 * MONEY IS COMPARED AS DECIMAL STRINGS, NEVER AS FLOAT.
 */
final class SettlementIdempotencyTest extends GameLaneTestCase
{
    use BuildsSettledDraw;

    private function adapter(): GameSettlementAdapter
    {
        return app(GameSettlementAdapter::class);
    }

    /**
     * Prize credits in the ledger, whatever their reference shape.
     */
    private function prizeCredits(): int
    {
        return FinancialTransaction::query()
            ->where('type', FinancialTransactionType::Payout->value)
            ->count();
    }

    // =========================================================================
    // The core property
    // =========================================================================

    public function test_settling_the_same_draw_twice_moves_the_money_once(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $this->bet($user, $draw, '3d_direct', '123', '10.00', 'idem-winner');

        $this->publishDigits('123');

        $first = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        $this->assertTrue($first['settled']);
        $this->assertFalse((bool) $first['result']['already_settled']);
        $this->assertSame('9990.00', $this->balanceOf($wallet));

        $balanceAfterFirst = $this->balanceOf($wallet);
        $creditsAfterFirst = $this->prizeCredits();

        // The same request, arriving again.
        $second = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        $this->assertSame($balanceAfterFirst, $this->balanceOf($wallet), 'a replay credits nobody a second time');
        $this->assertSame($creditsAfterFirst, $this->prizeCredits(), 'no second ledger entry');
        $this->assertSame(1, Payout::query()->count(), 'no second payout obligation');
        $this->assertSame(1, $this->prizeCredits(), 'exactly one prize credit for one winning slip');

        // The draw is still settled, and the slip still won exactly once.
        $this->assertSame('settled', (string) $second['result']['state_after']);
        $this->assertSame(BetStatus::Won, Bet::query()->firstOrFail()->status);
        $this->assertSame(1, Bet::query()->count());
    }

    public function test_a_replay_says_it_settled_nothing_instead_of_claiming_the_work_again(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        $this->bet($user, $draw, '3d_direct', '123', '10.00', 'idem-truthful');

        $this->publishDigits('123');

        $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);
        $replay = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        // THE RECEIPT IS THE POINT. A caller that cannot tell "I settled this" from "this
        // was already settled" cannot write a correct audit line, and a log that says the
        // money moved twice when it moved once is how a reconciliation goes wrong later.
        $this->assertFalse($replay['settled'], 'this call moved no money');
        $this->assertTrue((bool) $replay['result']['already_settled'], 'the draw was already settled');
        $this->assertSame(0, (int) $replay['result']['payouts_created']);
        $this->assertSame(0, (int) $replay['result']['bets_updated']);
    }

    public function test_a_replay_after_a_tariff_change_is_refused_rather_than_repriced(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $this->bet($user, $draw, '3d_direct', '123', '10.00', 'idem-tariff');

        $this->publishDigits('123');

        $first = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);
        $this->assertSame('9000.00', bcadd((string) $first['result']['total_prize'], '0', 2));

        $balanceAfterFirst = $this->balanceOf($wallet);

        // A config deploy changes the market's multiplier AFTER the draw was paid. The slip
        // was sold at 900x and its obligation is a fact, not a function of today's
        // configuration - so the platform must not quietly re-price it, and it does not:
        // the replay refuses and NAMES the disagreement, because a settlement whose stored
        // prize is not reproducible is exactly the thing an operator has to look at, not
        // something a retry should paper over.
        config()->set('lottery.markets.3d_direct.payout_multiplier', 500);

        try {
            $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);
            $this->fail('a settlement that is no longer reproducible must be reported, not recomputed');
        } catch (\App\Exceptions\LotteryGameException $e) {
            $this->assertSame('game_settlement_refused', $e->errorCode());
            $this->assertStringContainsString('9000.00', $e->getMessage(), 'the stored prize is named');
            $this->assertStringContainsString('5000.00', $e->getMessage(), 'so is the recomputed one');
        }

        // And nothing moved: the original obligation stands, unpaid a second time.
        $this->assertSame($balanceAfterFirst, $this->balanceOf($wallet));
        $this->assertSame('9000.00', bcadd((string) Payout::query()->firstOrFail()->amount, '0', 2));
        $this->assertSame(1, $this->prizeCredits());
    }

    public function test_a_replay_reconciles_the_same_winners_and_rewrites_nothing(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        $this->bet($user, $draw, '3d_direct', '123', '10.00', 'idem-recon-winner');
        $this->bet($user, $draw, '3d_direct', '456', '10.00', 'idem-recon-loser');

        $this->publishDigits('123');

        $first = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);
        $attributionBefore = GameBetAttribution::query()->orderBy('id')->get(['id', 'bet_id', 'number', 'potential_payout'])->toArray();

        $replay = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        $this->assertSame($first['winners'], $replay['winners']);
        $this->assertSame($first['considered'], $replay['considered']);
        $this->assertSame('123', $replay['reconciliation']['digits']);

        $this->assertSame(
            $attributionBefore,
            GameBetAttribution::query()->orderBy('id')->get(['id', 'bet_id', 'number', 'potential_payout'])->toArray(),
            'the lane attribution is evidence, not a scratch pad; a replay must not touch it',
        );
    }

    // =========================================================================
    // Repetition at the edges of the fixture
    // =========================================================================

    public function test_two_settlements_of_a_multi_winner_draw_do_not_multiply_the_payouts(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $this->bet($user, $draw, '3d_direct', '123', '10.00', 'idem-multi-one');
        $this->bet($user, $draw, '2d_bottom', '23', '10.00', 'idem-multi-two');

        $this->publishDigits('123');

        $first = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);
        $this->assertSame(2, $first['winners']);
        $this->assertSame(2, Payout::query()->count());

        $balanceAfterFirst = $this->balanceOf($wallet);
        // 1000.00 - 20.00 staked + 9000.00 + 900.00 prizes
        $this->assertSame('10880.00', $balanceAfterFirst);

        $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        $this->assertSame($balanceAfterFirst, $this->balanceOf($wallet));
        $this->assertSame(2, Payout::query()->count());
        $this->assertSame(2, $this->prizeCredits());
    }

    public function test_settling_a_draw_nobody_won_twice_is_also_a_no_op(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $this->bet($user, $draw, '3d_direct', '456', '10.00', 'idem-loser');

        $this->publishDigits('123');

        $first = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);
        $this->assertTrue($first['settled'], 'the draw WAS settled; it simply had no winner');

        $second = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        $this->assertTrue((bool) $second['result']['already_settled']);
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame(0, $this->prizeCredits());
        $this->assertSame('990.00', $this->balanceOf($wallet));
        $this->assertSame(BetStatus::Lost, Bet::query()->firstOrFail()->status);
    }

    public function test_addressing_the_same_draw_by_id_and_by_number_still_settles_it_once(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $this->bet($user, $draw, '3d_direct', '123', '10.00', 'idem-addressing');

        $this->publishDigits('123');

        // The console command addresses by number; the queue job addresses by id. Two
        // addressings is one draw.
        $byNumber = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);
        $byId = $this->adapter()->settleDraw((int) $draw->getKey());

        $this->assertTrue($byNumber['settled']);
        $this->assertFalse($byId['settled']);
        $this->assertSame($byNumber['draw_id'], $byId['draw_id']);
        $this->assertSame(1, Payout::query()->count());
        $this->assertSame('9990.00', $this->balanceOf($wallet));
    }

    public function test_a_refused_settlement_leaves_no_latch_that_blocks_the_real_one(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $this->bet($user, $draw, '3d_direct', '123', '10.00', 'idem-refused-first');

        // Attempted too early: the draw is open and has no published result.
        try {
            $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);
            $this->fail('an open draw cannot be settled');
        } catch (\App\Exceptions\LotteryGameException $e) {
            $this->assertSame('game_settlement_refused', $e->errorCode());
        }

        $this->assertSame(0, Payout::query()->count());
        $this->assertSame('990.00', $this->balanceOf($wallet));

        // The refusal must be a refusal and nothing more: no half-opened settlement run, no
        // "in progress" marker. The draw settles normally once its result is published.
        $this->publishDigits('123');

        $outcome = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        $this->assertTrue($outcome['settled']);
        $this->assertSame('inline', $outcome['mode']);
        $this->assertNull($outcome['run_id'], 'a refused attempt must not have left a run behind');
        $this->assertSame(1, Payout::query()->count());
        $this->assertSame('9990.00', $this->balanceOf($wallet));
    }

    public function test_the_wallet_credit_carries_one_idempotency_key_and_one_transaction(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        $this->bet($user, $draw, '3d_direct', '123', '10.00', 'idem-keyed');

        $this->publishDigits('123');

        $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);
        $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        $payout = Payout::query()->firstOrFail();

        // The credit is traceable to the payout, and there is exactly one of it.
        $credits = FinancialTransaction::query()
            ->where('type', FinancialTransactionType::Payout->value)
            ->get();

        $this->assertCount(1, $credits, 'two settlements, one prize credit');
        $this->assertSame((int) $payout->getKey(), (int) $credits->first()->reference_id);
        $this->assertSame(Payout::class, (string) $credits->first()->reference_type);
        $this->assertSame((int) $payout->financial_transaction_id, (int) $credits->first()->getKey());
    }

    public function test_a_replayed_settlement_does_not_reopen_the_draw_or_move_it_out_of_settled(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        $this->bet($user, $draw, '3d_direct', '123', '10.00', 'idem-state');

        $this->publishDigits('123');

        $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);
        $settledAt = Draw::query()->findOrFail($draw->getKey())->settled_at ?? null;

        $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        $after = Draw::query()->findOrFail($draw->getKey());

        $this->assertSame(
            \App\Enums\DrawStatus::Completed,
            $after->status,
            'a replay must not push a settled draw back into a settleable state',
        );
        $this->assertSame($settledAt, $after->settled_at, 'a replay does not restamp the settlement time');
    }

    public function test_the_prize_lands_in_the_wallet_the_slip_was_bought_from(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        // The same player also holds a winnings wallet in the same currency. Batch 1's
        // resolver is used by the purchase path and by settlement, so the prize must land
        // in the wallet the stake came out of - and only there. A lane that resolved its
        // own winner wallet could pay a slip into an account nobody reconciled.
        $winnings = Wallet::factory()->for($user)->withBalance('50.00')->create([
            'type' => \App\Enums\WalletType::Win->value,
        ]);

        $this->bet($user, $draw, '3d_direct', '123', '10.00', 'idem-two-wallets');

        $this->publishDigits('123');

        $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);
        $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        $this->assertSame(1, $this->prizeCredits(), 'one prize, one wallet, one credit');
        $this->assertSame(1, Payout::query()->count());

        $this->assertSame('9990.00', $this->balanceOf($wallet), 'the debited wallet is the credited wallet');
        $this->assertSame('50.00', bcadd((string) $winnings->fresh()->balance, '0', 2), 'the other wallet did not grow');

        $payout = Payout::query()->firstOrFail();
        $this->assertSame((int) $wallet->getKey(), (int) $payout->wallet_id, 'the payout names the wallet it credited');
    }
}
