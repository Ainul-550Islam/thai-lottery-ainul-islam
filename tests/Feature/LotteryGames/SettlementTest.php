<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\DTOs\LotteryGames\DigitBetSelection;
use App\DTOs\LotteryGames\DigitDrawResult;
use App\DTOs\LotteryGames\GameDefinition;
use App\Enums\BetStatus;
use App\Enums\DrawStatus;
use App\Exceptions\LotteryGameException;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\GameBetAttribution;
use App\Models\Payout;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LotteryGames\GameBetPlacementService;
use App\Services\LotteryGames\GameRegistry;
use App\Services\LotteryGames\GameResultIngestionService;
use Tests\Feature\LotteryGames\Concerns\BuildsSettledDraw;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * Paying the right people, through the settlement pipeline that already exists.
 *
 * WHY THIS SUITE EXISTS AT ALL, GIVEN BATCH 1 ALREADY SETTLES DRAWS
 * Because the digit lane's claims about settlement are precisely the claims that would be
 * catastrophic to get wrong and easy to fake:
 *
 *   §0.2  there is no second settlement engine. A digit bet is settled by
 *         ChunkedSettlementOrchestrator -> RealPrizeSettlementService, the same code that
 *         pays an ordinary 3D slip. The evidence asserted here is that the money moved
 *         through Batch 1's own records (bet status, Payout row, wallet balance) and that
 *         the lane wrote no money of its own.
 *   §0.6  money moves once. A settlement replayed after the fact must be a no-op, not a
 *         second credit; that has its own file (SettlementIdempotencyTest), and this one
 *         proves the FIRST settlement is a single, reconciled movement.
 *   §0.5  the result is the published one. Settlement pays from the digits that went
 *         through the confirmation boundary, never from a merely staged payload - so a
 *         staged import leaves the draw unsettleable rather than paid against a guess.
 *
 * HOW THE RECONCILIATION IS USED HERE
 * GameSettlementAdapter::verifyWinners() recounts the winners from the lane's own
 * attribution rows plus the digits the lane published. It is the independent second
 * opinion that makes "did settlement pay the right people?" answerable without trusting
 * settlement's arithmetic. The tests assert it BEFORE settlement (it predicts, and moves
 * nothing) and AFTER it (it agrees with what was paid), because a reconciliation that is
 * only checked against itself proves nothing.
 *
 * WHAT IS DELIBERATELY NOT TESTED HERE
 * Chunked runs, cursors, worker restarts and queue durability are Batch 1's suite and
 * SettlementIdempotencyTest's; this file stays in the inline mode a small draw takes so
 * that every assertion is about the lane rather than about queue infrastructure.
 *
 * MONEY IS COMPARED AS DECIMAL STRINGS, THROUGH bccomp/bcadd, NEVER AS FLOAT.
 */
final class SettlementTest extends GameLaneTestCase
{
    use BuildsSettledDraw;

    // =========================================================================
    // The happy path: a winner is paid by Batch 1, once
    // =========================================================================

    public function test_a_winning_digit_bet_is_paid_through_the_existing_settlement_pipeline(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $receipt = $this->bet($user, $draw, '3d_direct', '123', '10.00', 'settle-winner');

        // Buying the slip already moved money: 1000.00 - 10.00. Every later assertion
        // about the balance is measured from this point so a purchase-side change cannot
        // masquerade as a settlement-side one.
        $this->assertSame('990.00', $this->balanceOf($wallet));

        $published = $this->publishDigits('123');
        $this->assertTrue($published['published']);

        $outcome = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        // 1. Settlement went through the existing pipeline, in inline mode for a small draw.
        $this->assertSame('inline', $outcome['mode']);
        $this->assertTrue($outcome['settled'], 'this call moved the money');
        $this->assertNull($outcome['run_id'], 'an inline settlement opens no chunked run');
        $this->assertSame((int) $draw->getKey(), $outcome['draw_id']);

        // 2. Batch 1 paid the winner: the slip is Won, a payout exists, the wallet is credited.
        $bet = Bet::query()->findOrFail($receipt['bet_id']);
        $this->assertSame(BetStatus::Won, $bet->status);
        $this->assertSame(1, Payout::query()->count(), 'one payout, created by Batch 1');

        // 10.00 at the 3d_direct market's 900x = 9000.00, added to the 990.00 left after stake.
        $this->assertSame('9990.00', $this->balanceOf($wallet));
        $this->assertSame(
            0,
            bccomp($this->balanceOf($wallet), bcadd('990.00', (string) $bet->potential_payout, 2), 2),
            'the credit is exactly the slip\'s own potential payout',
        );

        $this->assertSame(1, (int) $outcome['result']['payouts_created']);
        $this->assertSame(1, (int) $outcome['result']['bets_updated']);
        $this->assertFalse((bool) $outcome['result']['already_settled']);
        $this->assertSame('settled', (string) $outcome['result']['state_after'], 'the draw reached Batch 1\'s Settled state');

        // 3. The lane's reconciliation agrees with what Batch 1 paid, after the fact.
        $this->assertSame(1, $outcome['considered'], 'one attributed bet on this draw');
        $this->assertSame(1, $outcome['winners']);
        $this->assertSame('123', $outcome['reconciliation']['digits']);
        $this->assertNull($outcome['reconciliation']['reason']);
    }

    public function test_a_losing_digit_bet_is_not_paid_and_the_balance_does_not_move(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $receipt = $this->bet($user, $draw, '3d_direct', '456', '10.00', 'settle-loser');

        $this->publishDigits('123');

        $outcome = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        $bet = Bet::query()->findOrFail($receipt['bet_id']);
        $this->assertSame(BetStatus::Lost, $bet->status);
        $this->assertSame(0, Payout::query()->count(), 'a loser is paid nothing');

        // The stake left at purchase and NOTHING came back.
        $this->assertSame('990.00', $this->balanceOf($wallet));

        $this->assertSame(0, $outcome['winners']);
        $this->assertSame(1, $outcome['considered']);
        $this->assertSame(0, (int) $outcome['result']['payouts_created']);
        $this->assertSame('0.00', bcadd((string) $outcome['result']['total_prize'], '0', 2));
    }

    public function test_a_suffix_market_winner_is_paid_on_the_bottom_two_digits(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        // 2d_bottom reads the LAST two digits of the published number, so '23' wins
        // against a published '123'. The publication bridge maps bottom_two to 'last:2',
        // which is what makes this a real settlement rather than a fixture convention.
        $receipt = $this->bet($user, $draw, '2d_bottom', '23', '10.00', 'settle-suffix');

        $this->publishDigits('123');

        $outcome = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        $bet = Bet::query()->findOrFail($receipt['bet_id']);
        $this->assertSame(BetStatus::Won, $bet->status);
        $this->assertSame(1, $outcome['winners']);
        // 10.00 at 2d_bottom's 90x = 900.00 on top of the 990.00 left after the stake.
        $this->assertSame('1890.00', $this->balanceOf($wallet));
    }

    public function test_a_settlement_in_the_disabled_safety_mode_books_the_win_and_credits_nobody(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $receipt = $this->bet($user, $draw, '3d_direct', '123', '10.00', 'settle-disabled');

        $this->publishDigits('123');

        // The platform as shipped: settlement is armed for computation, not for money.
        config()->set('finance.prize_payout.safety_mode', 'DISABLED');

        $outcome = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        // The winner is still identified - the reconciliation does not depend on the
        // safety mode - and the slip is still marked Won: the outcome of the draw is a
        // fact, and pretending nobody won would be a lie about the draw.
        $this->assertSame(1, $outcome['winners']);
        $this->assertSame(BetStatus::Won, Bet::query()->findOrFail($receipt['bet_id'])->status);

        // But the money did not move: one Pending obligation, no credit, no completed payout.
        $payout = Payout::query()->firstOrFail();
        $this->assertSame(\App\Enums\PayoutStatus::Pending, $payout->status);
        $this->assertSame('9000.00', bcadd((string) $payout->amount, '0', 2));
        $this->assertSame('990.00', $this->balanceOf($wallet), 'a suppressed credit is not a credit');
        $this->assertNull($payout->financial_transaction_id);
    }

    // =========================================================================
    // The reconciliation: a second opinion that moves nothing
    // =========================================================================

    public function test_reconciliation_predicts_the_winners_without_moving_any_money(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $winner = $this->bet($user, $draw, '3d_direct', '123', '10.00', 'recon-winner');
        $loser = $this->bet($user, $draw, '3d_direct', '456', '10.00', 'recon-loser');

        $this->publishDigits('123');

        $balanceBefore = $this->balanceOf($wallet);
        $payoutsBefore = Payout::query()->count();
        $statusesBefore = Bet::query()->orderBy('id')->pluck('status')->all();

        $reconciliation = $this->adapter()->verifyWinners('lane_game', self::SETTLEMENT_DRAW_NO);

        // 1. It names the right winner, and the right number of them.
        $this->assertSame('123', $reconciliation['digits']);
        $this->assertSame(2, $reconciliation['considered']);
        $this->assertSame(1, $reconciliation['winners']);
        $this->assertNull($reconciliation['reason']);

        // 2. It is READ-ONLY: no money, no payouts, no status changes.
        $this->assertSame($balanceBefore, $this->balanceOf($wallet), 'a reconciliation never pays');
        $this->assertSame($payoutsBefore, Payout::query()->count());
        $this->assertSame($statusesBefore, Bet::query()->orderBy('id')->pluck('status')->all());

        // 3. Each line carries the facts an operator reconciles against.
        $lines = collect($reconciliation['lines'])->keyBy('bet_id');

        $winnerLine = $lines->get($winner['bet_id']);
        $this->assertTrue($winnerLine['won']);
        $this->assertSame('9000.00', bcadd((string) $winnerLine['potential_payout'], '0', 2));
        $this->assertSame('real', (string) $winnerLine['funding_source']);

        $loserLine = $lines->get($loser['bet_id']);
        $this->assertFalse($loserLine['won']);
        $this->assertSame('456', (string) $loserLine['number']);
    }

    public function test_reconciliation_refuses_a_verdict_when_the_result_is_not_published(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        $this->bet($user, $draw, '3d_direct', '123', '10.00', 'recon-blind');

        // No publication: the draw is closed but its digits are not official yet.
        $this->closeDraw($draw);

        $reconciliation = $this->adapter()->verifyWinners('lane_game', self::SETTLEMENT_DRAW_NO);

        // Without published digits there is no verdict to give, and inventing one would
        // make the reconciliation itself the thing that is wrong.
        $this->assertNull($reconciliation['digits']);
        $this->assertSame('result_not_published', $reconciliation['reason']);
        $this->assertSame(1, $reconciliation['considered']);
        $this->assertSame(0, $reconciliation['winners']);

        foreach ($reconciliation['lines'] as $line) {
            $this->assertNull($line['won'], 'a blind reconciliation says "unknown", not "lost"');
            $this->assertSame('result_not_published', $line['reason']);
        }

        $this->assertSame(null, $this->adapter()->publishedDigits('lane_game', self::SETTLEMENT_DRAW_NO));
    }

    public function test_a_merely_staged_payload_does_not_make_the_draw_settleable(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $this->bet($user, $draw, '3d_direct', '123', '10.00', 'staged-only');

        $draw = $this->closeDraw($draw);

        // Staged, never published: the digits exist in the import table and nowhere else.
        $this->ingestion()->stage(
            $this->definition(),
            new DigitDrawResult(
                gameCode: 'lane_game',
                drawNo: (string) $draw->draw_number,
                digits: '123',
                drawnAt: now()->toIso8601String(),
                source: 'official',
            ),
        );

        $this->assertNull(
            $this->adapter()->publishedDigits('lane_game', self::SETTLEMENT_DRAW_NO),
            'staged digits are not the published result',
        );

        $balanceBefore = $this->balanceOf($wallet);

        try {
            $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);
            $this->fail('settlement must refuse a draw whose result was never published');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_settlement_refused', $e->errorCode());
        }

        $this->assertSame($balanceBefore, $this->balanceOf($wallet), 'a refused settlement pays nobody');
        $this->assertSame(0, Payout::query()->count());
    }

    // =========================================================================
    // Refusals
    // =========================================================================

    public function test_settling_an_undeclared_game_is_refused(): void
    {
        try {
            $this->adapter()->settle('no_such_game', self::SETTLEMENT_DRAW_NO);
            $this->fail('an undeclared game cannot be settled');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_unknown', $e->errorCode());
        }
    }

    public function test_settling_a_draw_number_that_does_not_exist_is_refused(): void
    {
        $this->ready();

        try {
            $this->adapter()->settle('lane_game', 'LANE-NOT-A-DRAW');
            $this->fail('a draw number that names no draw cannot be settled');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_settlement_refused', $e->errorCode());
            $this->assertSame('LANE-NOT-A-DRAW', (string) ($e->context()['draw_no'] ?? ''));
        }

        // The refusal is a refusal, not a partial settlement of some other draw.
        $this->assertSame(0, Payout::query()->count());
    }

    public function test_settling_a_still_open_draw_is_refused_in_this_lanes_vocabulary(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $this->bet($user, $draw, '3d_direct', '123', '10.00', 'settle-open');

        $balanceBefore = $this->balanceOf($wallet);

        // The draw is open and its result is not published. Batch 1's lifecycle guard
        // refuses (DrawLifecycleException, a RuntimeException); the adapter converts that
        // into THIS lane's error code so a caller handles one vocabulary, not two.
        try {
            $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);
            $this->fail('an open draw cannot be settled');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_settlement_refused', $e->errorCode());
            $this->assertSame('lane_game', (string) ($e->context()['game_code'] ?? ''));
            $this->assertSame('settlement.inline', (string) ($e->context()['stage'] ?? ''));
        }

        $this->assertSame($balanceBefore, $this->balanceOf($wallet));

        $bet = Bet::query()->firstOrFail();
        $this->assertSame(BetStatus::Active, $bet->status, 'a refused settlement leaves the slip alone');
    }

    public function test_settling_an_unknown_draw_id_is_refused(): void
    {
        try {
            $this->adapter()->settleDraw(987654321);
            $this->fail('an unknown draw id cannot be settled');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_settlement_refused', $e->errorCode());
            $this->assertSame(987654321, (int) ($e->context()['draw_id'] ?? 0));
        }
    }

    // =========================================================================
    // Addressing: the same draw, by number and by id
    // =========================================================================

    public function test_the_draw_can_be_addressed_by_its_id_as_well_as_its_number(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $this->bet($user, $draw, '3d_direct', '123', '10.00', 'settle-by-id');

        $this->publishDigits('123');

        // The queue jobs and the console command address a draw by id; an operator
        // addresses it by number. Both must reach the same settlement.
        $outcome = $this->adapter()->settleDraw((int) $draw->getKey());

        $this->assertSame('lane_game', $outcome['game_code']);
        $this->assertSame(self::SETTLEMENT_DRAW_NO, $outcome['draw_no']);
        $this->assertSame((int) $draw->getKey(), $outcome['draw_id']);
        $this->assertSame(1, $outcome['winners']);
        $this->assertSame('9990.00', $this->balanceOf($wallet));
    }

    public function test_settlement_reports_every_attributed_bet_of_the_draw_it_considered(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        $this->bet($user, $draw, '3d_direct', '123', '10.00', 'multi-one');
        $this->bet($user, $draw, '3d_direct', '456', '10.00', 'multi-two');
        $this->bet($user, $draw, '2d_bottom', '23', '10.00', 'multi-three');

        $this->publishDigits('123');

        $outcome = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        $this->assertSame(3, $outcome['considered'], 'three attributed bets');
        $this->assertSame(2, $outcome['winners'], "'123' exact and '23' on the bottom two");
        $this->assertSame(3, (int) $outcome['result']['bets_updated']);
        $this->assertSame(3, GameBetAttribution::query()->where('draw_id', (int) $draw->getKey())->count());
    }
}
