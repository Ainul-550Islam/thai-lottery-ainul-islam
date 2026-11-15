<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\DTOs\LotteryGames\DigitBetSelection;
use App\Enums\BetStatus;
use App\Exceptions\LotteryGameException;
use App\Models\Bet;
use App\Models\GameBetAttribution;
use App\Models\Ticket;
use App\Services\LotteryGames\GameBetPlacementService;
use App\Services\LotteryGames\LiabilityExposureService;
use App\Services\LotteryGames\PayoutCalculator;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * Placing one digit bet, end to end.
 *
 * WHY THIS SUITE IS THE ONE THAT PROVES THE LANE IS REAL
 * Everything else in Batch 2 is a decision. This is the only path that turns a decision
 * into money: a wallet is debited, a ledger entry is written, a bet and its items exist,
 * a liability counter moves, and a game attribution records what the bet MEANS. If any of
 * that is wrong, the platform is either selling bets it cannot settle or settling bets for
 * an amount nobody reserved.
 *
 * THE ORDER OF OPERATIONS IS THE DESIGN, SO EACH STEP IS ASSERTED
 *   1. validate the selection (digits as strings, correct width for the market)
 *   2. all nine availability gates
 *   3. the draw, inside its betting window
 *   4. stake bounds from configuration
 *   5. the Batch-1 market that will settle this bet
 *   6. funding (cash, or a voucher that is validated AND covers the stake)
 *   7. reserve liability          <- the step that can refuse for risk
 *   8. spend through Batch 1's BetPurchaseService
 *   9. attribute the game facts
 * Steps 7 and 8 are in that order for one reason: a risk refusal must not spend money.
 * The tests below check both directions of that claim — a refused reservation leaves the
 * wallet untouched, and a refused purchase gives the reservation back.
 *
 * WHY NOTHING IS ASSERTED ABOUT Batch-1 INTERNALS
 * The bet, the ticket, the ledger entry and the balance are Batch 1's contract, already
 * covered by its own suites. Here they are only used as EVIDENCE that the digit lane went
 * through the existing pipeline (Rule §0.2) rather than beside it: exactly one bet, one
 * ticket, one debit, and a deliberate absence of any batch-2 wallet write.
 *
 * MONEY IS COMPARED AS STRINGS THROUGHOUT.
 */
final class GameBetPlacementTest extends GameLaneTestCase
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
     * @param  array<string, mixed>  $overrides
     * @return array{game: \App\Models\LotteryGame, draw: \App\Models\Draw, user: \App\Models\User, wallet: \App\Models\Wallet}
     */
    private function ready(array $overrides = []): array
    {
        $lane = $this->openLane('lane_game', $overrides);
        $player = $this->player('1000.00');

        $this->limitFor($lane['draw'], '3d_direct', '123');
        $this->limitFor($lane['draw'], '2d_bottom', '45');

        return [...$lane, ...$player];
    }

    // =========================================================================
    // The happy path
    // =========================================================================

    public function test_a_placed_bet_is_priced_recorded_attributed_and_reserved(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $receipt = $this->placement()->place(
            userId: (int) $user->getKey(),
            selection: new DigitBetSelection('lane_game', '3d_direct', '123', '10.00'),
            drawId: (int) $draw->getKey(),
            idempotencyKey: $this->key('placement-one'),
        );

        // 1. The receipt describes the bet the platform actually holds.
        $this->assertSame('accepted', $receipt['status']);
        $this->assertSame('lane_game', $receipt['game_code']);
        $this->assertSame('3d_direct', $receipt['bet_type']);
        $this->assertSame('123', $receipt['number']);
        $this->assertSame('10.00', $receipt['stake']);
        $this->assertSame('9000.00', $receipt['potential_payout'], '10.00 at 900x');
        $this->assertSame('3d_direct', $receipt['market_key']);
        $this->assertSame('real', $receipt['funding_source'], 'cash funding is the Real source');
        $this->assertSame((int) $draw->getKey(), $receipt['draw_id']);

        // 2. Money moved through Batch 1 and only through Batch 1.
        $this->assertSame('990.00', $wallet->fresh()->balance);

        $bet = Bet::query()->findOrFail($receipt['bet_id']);
        $this->assertSame((int) $user->getKey(), (int) $bet->user_id);
        $this->assertSame((int) $draw->getKey(), (int) $bet->draw_id);
        $this->assertSame('10.00', (string) $bet->stake_amount);
        $this->assertSame(BetStatus::Active, $bet->status, 'a freshly purchased bet is Active in Batch 1');
        $this->assertSame(1, Bet::query()->count(), 'exactly one bet, not a parallel one');
        $this->assertSame(1, Ticket::query()->count(), 'the ticket is Batch 1\'s, unchanged');

        // 3. The digit meaning is recorded beside the bet, not inside it.
        $attribution = GameBetAttribution::query()->findOrFail($receipt['attribution_id']);
        $this->assertSame($receipt['bet_id'], (int) $attribution->bet_id);
        $this->assertSame('lane_game', (string) $attribution->game_code);
        $this->assertSame('123', (string) $attribution->number);
        // sqlite returns the stored decimal without trailing scale; normalise with bcmath
        // rather than trusting a formatted round trip.
        $this->assertSame('9000.00', bcadd((string) $attribution->potential_payout, '0', 2));

        // 4. The liability counter moved by the payout, not the stake.
        $this->assertSame('9000.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));
    }

    public function test_a_bet_placed_without_a_draw_id_lands_on_the_open_draw(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        $receipt = $this->placement()->place(
            userId: (int) $user->getKey(),
            selection: new DigitBetSelection('lane_game', '3d_direct', '123', '10.00'),
            drawId: 0,
            idempotencyKey: $this->key('open-draw'),
        );

        $this->assertSame((int) $draw->getKey(), $receipt['draw_id']);
    }

    public function test_the_same_number_can_be_placed_across_two_markets(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        $this->placement()->place(
            (int) $user->getKey(),
            new DigitBetSelection('lane_game', '3d_direct', '123', '10.00'),
            (int) $draw->getKey(),
            $this->key('market-a'),
        );

        $this->placement()->place(
            (int) $user->getKey(),
            new DigitBetSelection('lane_game', '3d_any_order', '123', '10.00'),
            (int) $draw->getKey(),
            $this->key('market-b'),
        );

        // 900x exact and 120x any-order: two counters, two prices, one draw.
        $this->assertSame('9000.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));
        $this->assertSame('1200.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_any_order'));
        $this->assertSame('10200.00', $this->exposure()->reservedPotentialPayout($draw->getKey(), 'lane_game'));
        $this->assertSame('980.00', $user->wallet->fresh()->balance);
    }

    // =========================================================================
    // Refusals that happen BEFORE any money moves
    // =========================================================================

    public function test_a_stake_below_the_configured_minimum_is_refused(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        try {
            $this->placement()->place(
                (int) $user->getKey(),
                new DigitBetSelection('lane_game', '3d_direct', '123', '0.50'),
                (int) $draw->getKey(),
                $this->key('min-stake'),
            );
            $this->fail('a stake below min_stake must be refused');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_not_sellable', $e->errorCode());
            $this->assertStringContainsString('stake', (string) $e->context()['reason']);
        }

        $this->assertSame('1000.00', $wallet->fresh()->balance, 'nothing was spent');
        $this->assertSame(0, Bet::query()->count());
        $this->assertSame('0.00', $this->exposure()->reservedPotentialPayout($draw->getKey(), 'lane_game'));
    }

    public function test_a_stake_above_the_configured_maximum_is_refused(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        try {
            $this->placement()->place(
                (int) $user->getKey(),
                new DigitBetSelection('lane_game', '3d_direct', '123', '5000.00'),
                (int) $draw->getKey(),
                $this->key('max-stake'),
            );
            $this->fail('a stake above max_stake must be refused');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_not_sellable', $e->errorCode());
        }

        $this->assertSame(0, Bet::query()->count());
    }

    public function test_a_selection_of_the_wrong_width_is_refused_by_the_validator_not_the_store(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        try {
            $this->placement()->place(
                (int) $user->getKey(),
                new DigitBetSelection('lane_game', '3d_direct', '12', '10.00'),
                (int) $draw->getKey(),
                $this->key('short-number'),
            );
            $this->fail('a two-digit selection in a three-digit market must be refused');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_number_invalid', $e->errorCode());
        }

        $this->assertSame('1000.00', $wallet->fresh()->balance);
        $this->assertSame(0, Bet::query()->count());
    }

    public function test_a_non_digit_selection_is_refused(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        $this->expectException(LotteryGameException::class);

        try {
            $this->placement()->place(
                (int) $user->getKey(),
                new DigitBetSelection('lane_game', '3d_direct', 'abc', '10.00'),
                (int) $draw->getKey(),
                $this->key('letters'),
            );
        } catch (LotteryGameException $e) {
            $this->assertSame('game_number_invalid', $e->errorCode());
            $this->assertSame(0, Bet::query()->count());

            throw $e;
        }
    }

    public function test_a_game_that_is_not_sellable_is_refused_before_the_draw_is_even_read(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        // Close the deployment switch: the game is configured, verified and drawable, but
        // the lane is shut.
        $this->enableGameLane(false);

        try {
            $this->placement()->place(
                (int) $user->getKey(),
                new DigitBetSelection('lane_game', '3d_direct', '123', '10.00'),
                (int) $draw->getKey(),
                $this->key('lane-closed'),
            );
            $this->fail('a game that is not sellable must be refused');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_not_sellable', $e->errorCode());
            $this->assertSame('game_lane_disabled', $e->context()['reason']);
        }

        $this->assertSame('1000.00', $wallet->fresh()->balance);
        $this->assertSame(0, Bet::query()->count());
    }

    public function test_a_closed_draw_is_refused_with_its_own_reason(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        $draw->forceFill(['betting_close_at' => now()->subMinute()])->save();

        try {
            $this->placement()->place(
                (int) $user->getKey(),
                new DigitBetSelection('lane_game', '3d_direct', '123', '10.00'),
                (int) $draw->getKey(),
                $this->key('closed-draw'),
            );
            $this->fail('a draw whose window has closed must refuse the bet');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_not_sellable', $e->errorCode());
            // The nine gates run before the draw is read, so a draw whose window has
            // closed fails gate 7 rather than reaching the draw-specific refusal. Both
            // answers refuse; this asserts which one an operator will actually see.
            $this->assertSame('game_no_open_draw', $e->context()['reason']);
        }

        $this->assertSame(0, Bet::query()->count());
    }

    public function test_a_draw_of_another_game_is_refused(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();
        $foreign = $this->openDraw('other_game');

        try {
            $this->placement()->place(
                (int) $user->getKey(),
                new DigitBetSelection('lane_game', '3d_direct', '123', '10.00'),
                (int) $foreign->getKey(),
                $this->key('foreign-draw'),
            );
            $this->fail('a bet may only be placed on a draw of its own game');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_not_sellable', $e->errorCode());
            $this->assertSame('game_draw_unavailable', $e->context()['reason']);
        }

        // The legitimate draw is untouched.
        $this->assertSame('0.00', $this->exposure()->reservedPotentialPayout($draw->getKey(), 'lane_game'));
    }

    public function test_a_market_with_no_settlement_key_is_refused_as_not_configured(): void
    {
        // A market that exists but maps to nothing: the bet could never settle, so it is
        // refused outright rather than sold and stranded.
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready([
            'bet_types' => [
                '3d_direct' => [
                    'selection_digits' => 3,
                    'match_mode' => 'exact',
                    'payout_multiplier' => '900',
                ],
                '2d_bottom' => [
                    'selection_digits' => 2,
                    'match_mode' => 'suffix',
                    'payout_multiplier' => '90',
                    'market_key' => '2d_bottom',
                ],
            ],
        ]);

        try {
            $this->placement()->place(
                (int) $user->getKey(),
                new DigitBetSelection('lane_game', '3d_direct', '123', '10.00'),
                (int) $draw->getKey(),
                $this->key('no-market'),
            );
            $this->fail('an unmapped market must be refused');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_not_configured', $e->errorCode());
            $this->assertContains('bet_types.3d_direct.market_key', $e->context()['missing']);
        }

        $this->assertSame('1000.00', $wallet->fresh()->balance);
    }

    public function test_an_empty_idempotency_key_is_refused(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        try {
            $this->placement()->place(
                (int) $user->getKey(),
                new DigitBetSelection('lane_game', '3d_direct', '123', '10.00'),
                (int) $draw->getKey(),
                '   ',
            );
            $this->fail('a blank idempotency key must be refused');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_not_sellable', $e->errorCode());
            $this->assertSame('game_idempotency_key_required', $e->context()['reason']);
        }
    }

    // =========================================================================
    // The risk refusal must not spend money
    // =========================================================================

    public function test_a_liability_refusal_leaves_the_wallet_and_the_store_untouched(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready([
            'max_liability_per_number' => '5000.00',
        ]);

        try {
            // 10.00 at 900x is 9,000: over the 5,000 per-number ceiling.
            $this->placement()->place(
                (int) $user->getKey(),
                new DigitBetSelection('lane_game', '3d_direct', '123', '10.00'),
                (int) $draw->getKey(),
                $this->key('cap-refusal'),
            );
            $this->fail('the liability ceiling must refuse this bet');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_liability_exceeded', $e->errorCode());
        }

        $this->assertSame('1000.00', $wallet->fresh()->balance, 'a risk refusal must not spend the stake');
        $this->assertSame(0, Bet::query()->count());
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame('0.00', $this->exposure()->reservedPotentialPayout($draw->getKey(), 'lane_game'));
    }

    public function test_an_insufficient_balance_unwinds_the_reservation_it_had_already_taken(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();
        $user->wallet->forceFill(['balance' => '5.00'])->save();

        try {
            $this->placement()->place(
                (int) $user->getKey(),
                new DigitBetSelection('lane_game', '3d_direct', '123', '10.00'),
                (int) $draw->getKey(),
                $this->key('insufficient'),
            );
            $this->fail('a stake the wallet cannot fund must be refused');
        } catch (\Throwable $e) {
            // Batch 1's own refusal code: the lane does not translate it, it propagates it.
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(0, Bet::query()->count(), 'nothing was sold');
        $this->assertSame(
            '0.00',
            $this->exposure()->reservedPotentialPayout($draw->getKey(), 'lane_game'),
            'the reservation made before the purchase must be given back',
        );
        $this->assertSame('5.00', $wallet->fresh()->balance);
    }

    // =========================================================================
    // Pricing
    // =========================================================================

    public function test_the_potential_payout_comes_from_the_configured_multiplier(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready([
            'bet_types' => [
                '3d_direct' => [
                    'selection_digits' => 3,
                    'match_mode' => 'exact',
                    'payout_multiplier' => '450',
                    'market_key' => '3d_direct',
                ],
                '2d_bottom' => [
                    'selection_digits' => 2,
                    'match_mode' => 'suffix',
                    'payout_multiplier' => '90',
                    'market_key' => '2d_bottom',
                ],
            ],
        ]);

        $receipt = $this->placement()->place(
            (int) $user->getKey(),
            new DigitBetSelection('lane_game', '3d_direct', '123', '10.00'),
            (int) $draw->getKey(),
            $this->key('custom-multiplier'),
        );

        $this->assertSame('4500.00', $receipt['potential_payout']);
        $this->assertSame('4500.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));

        // The calculator is the one authority for that arithmetic.
        $this->assertSame(
            '4500.00',
            (new PayoutCalculator)->potentialPayout(
                app(\App\Services\LotteryGames\GameRegistry::class)->definition('lane_game'),
                '3d_direct',
                '10.00',
            ),
        );
    }

    public function test_a_leading_zero_is_preserved_in_the_receipt_and_the_counter(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();
        $this->limitFor($draw, '3d_direct', '007');

        $receipt = $this->placement()->place(
            (int) $user->getKey(),
            new DigitBetSelection('lane_game', '3d_direct', '007', '10.00'),
            (int) $draw->getKey(),
            $this->key('leading-zero'),
        );

        $this->assertSame('007', $receipt['number'], 'a leading zero is part of the selection');
        $this->assertSame('9000.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '007', '3d_direct'));
        $this->assertNull(
            $this->exposure()->row($draw->getKey(), 'lane_game', '7', '3d_direct'),
            'the same bet must not also exist without its leading zeros',
        );
    }
}
