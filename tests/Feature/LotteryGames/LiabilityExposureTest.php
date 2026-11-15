<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\Exceptions\LotteryGameException;
use App\Models\GameNumberExposure;
use App\Services\LotteryGames\LiabilityExposureService;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * The operator's reserved liability: what the platform will owe if a number wins.
 *
 * WHY THIS IS THE RISK CENTRE OF A DIGIT GAME
 * A 900x market turns a 50 THB stake into a 45,000 THB obligation on one number. Two
 * hundred such bets on "123" is nine million, on one outcome, decided once a day. The
 * platform therefore keeps an incrementable counter per (draw, market, number) and refuses
 * any bet that would push a counter past either ceiling. Everything about this component
 * is decided under a row lock because the check and the increment must not be separable:
 * two concurrent placements that both read "room left" and both write would each be
 * individually correct and jointly fatal.
 *
 * WHAT THIS SUITE PROVES
 *   - the counters are the SUM of reserved potential payout, not of stakes (the stake is
 *     not what the operator owes; the payout is)
 *   - both ceilings bind, and each refusal names which ceiling and by how much
 *   - a refusal writes NOTHING: the transaction rolls back every earlier increment in the
 *     same call, so a multi-selection reservation is all-or-nothing
 *   - release gives liability back, is idempotent, and can never drive a counter negative
 *   - an unconfigured ceiling refuses instead of meaning "unlimited"
 *   - a selection of the wrong width or a market the game does not sell is refused before
 *     any counter is touched
 *
 * WHY CONCURRENCY IS NOT TESTED HERE
 * Single-call correctness is this file's job. Two simultaneous placements racing for the
 * last headroom is LiabilityConcurrencyTest's job, because it needs a second connection
 * and a different technique entirely.
 *
 * ALL MONEY IS COMPARED AS STRINGS
 * '9000.00', never 9000.0. A float comparison here would hide a scale or rounding defect
 * in exactly the component whose job is to be exact.
 */
final class LiabilityExposureTest extends GameLaneTestCase
{
    private function exposure(): LiabilityExposureService
    {
        return app(LiabilityExposureService::class);
    }

    /**
     * A declared game with the lane's caps: 50,000 per number, 100,000 per draw.
     *
     * @return array{draw: \App\Models\Draw}
     */
    private function game(string $code = 'lane_game', array $overrides = []): array
    {
        $this->declareGame($code, $this->laneGameConfig($code, array_merge([
            'max_liability_per_number' => '50000.00',
            'max_liability_per_draw' => '100000.00',
        ], $overrides)));

        return ['draw' => $this->openDraw($code)];
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return list<array<string, mixed>>
     */
    private function one(array $selection): array
    {
        return [$selection];
    }

    // =========================================================================
    // Reserving
    // =========================================================================

    public function test_a_reservation_records_the_potential_payout_the_operator_owes(): void
    {
        ['draw' => $draw] = $this->game();

        $result = $this->exposure()->reserve($draw->getKey(), 'lane_game', $this->one([
            'number' => '123',
            'bet_type' => '3d_direct',
            'stake' => '10.00',
        ]));

        $this->assertSame('lane_game', $result['game_code']);
        $this->assertSame((int) $draw->getKey(), $result['draw_id']);

        // 10.00 stake at 900x: the operator owes 9,000.00, not 10.00.
        $this->assertSame('9000.00', $result['draw_potential_payout']);
        $this->assertSame('9000.00', $result['per_number']['3d_direct/123']);
        $this->assertSame('9000.00', $result['reserved'][0]['number_total']);
        $this->assertSame('10.00', $result['reserved'][0]['stake']);

        $row = $this->exposure()->row($draw->getKey(), 'lane_game', '123', '3d_direct');

        $this->assertInstanceOf(GameNumberExposure::class, $row);
        $this->assertSame('9000.00', $row->potentialPayoutTotal());
        $this->assertSame('10.00', $row->stakeTotal());
        $this->assertSame(1, (int) $row->version, 'the first reservation is version 1');
    }

    public function test_a_second_bet_on_the_same_number_accumulates_and_versions(): void
    {
        ['draw' => $draw] = $this->game();

        $selection = $this->one(['number' => '123', 'bet_type' => '3d_direct', 'stake' => '10.00']);

        $this->exposure()->reserve($draw->getKey(), 'lane_game', $selection);
        $second = $this->exposure()->reserve($draw->getKey(), 'lane_game', $selection);

        $this->assertSame('18000.00', $second['per_number']['3d_direct/123']);
        $this->assertSame('18000.00', $second['draw_potential_payout']);

        $row = $this->exposure()->row($draw->getKey(), 'lane_game', '123', '3d_direct');

        $this->assertSame('18000.00', $row->potentialPayoutTotal());
        $this->assertSame('20.00', $row->stakeTotal());
        $this->assertSame(2, (int) $row->version);
    }

    public function test_different_numbers_and_markets_keep_separate_counters(): void
    {
        ['draw' => $draw] = $this->game();

        $this->exposure()->reserve($draw->getKey(), 'lane_game', [
            ['number' => '123', 'bet_type' => '3d_direct', 'stake' => '10.00'],
            ['number' => '45', 'bet_type' => '2d_bottom', 'stake' => '100.00'],
        ]);

        $this->assertSame('9000.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));
        $this->assertSame('9000.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '45', '2d_bottom'));

        // 100.00 at 90x on the 2d market.
        $this->assertSame('18000.00', $this->exposure()->reservedPotentialPayout($draw->getKey(), 'lane_game'));
    }

    public function test_counters_are_scoped_to_one_draw(): void
    {
        ['draw' => $draw] = $this->game();
        $other = $this->openDraw('lane_game', \Carbon\CarbonImmutable::now()->addDay());

        $this->exposure()->reserve($draw->getKey(), 'lane_game', $this->one([
            'number' => '123', 'bet_type' => '3d_direct', 'stake' => '10.00',
        ]));

        $this->assertSame('9000.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));
        $this->assertSame('0.00', $this->exposure()->exposureFor($other->getKey(), 'lane_game', '123', '3d_direct'));
        $this->assertSame('0.00', $this->exposure()->reservedPotentialPayout($other->getKey(), 'lane_game'));
    }

    // =========================================================================
    // The ceilings
    // =========================================================================

    public function test_the_per_number_ceiling_refuses_and_names_itself(): void
    {
        ['draw' => $draw] = $this->game();

        // 50.00 at 900x is 45,000: under the 50,000 per-number cap.
        $this->exposure()->reserve($draw->getKey(), 'lane_game', $this->one([
            'number' => '123', 'bet_type' => '3d_direct', 'stake' => '50.00',
        ]));

        try {
            // Another 10.00 would take the number to 54,000: over the cap.
            $this->exposure()->reserve($draw->getKey(), 'lane_game', $this->one([
                'number' => '123', 'bet_type' => '3d_direct', 'stake' => '10.00',
            ]));
            $this->fail('the per-number ceiling must refuse this bet');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_liability_exceeded', $e->errorCode());

            $context = $e->context();
            $this->assertSame('per_number', $context['cap_scope']);
            $this->assertSame('50000.00', $context['cap']);
            $this->assertSame('54000.00', $context['would_be']);
            $this->assertSame('45000.00', $context['current']);
            $this->assertSame('123', $context['number']);
        }

        // Nothing was written by the refusal: the counter is exactly where it was.
        $this->assertSame('45000.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));
        $this->assertSame('1', (string) $this->exposure()->row($draw->getKey(), 'lane_game', '123', '3d_direct')->version);
    }

    public function test_the_per_draw_ceiling_refuses_across_different_numbers(): void
    {
        ['draw' => $draw] = $this->game();

        // Three numbers, each comfortably under the per-number cap, together over the
        // per-draw cap of 100,000.
        $this->exposure()->reserve($draw->getKey(), 'lane_game', [
            ['number' => '123', 'bet_type' => '3d_direct', 'stake' => '50.00'],
            ['number' => '456', 'bet_type' => '3d_direct', 'stake' => '50.00'],
        ]);

        try {
            $this->exposure()->reserve($draw->getKey(), 'lane_game', $this->one([
                'number' => '789', 'bet_type' => '3d_direct', 'stake' => '20.00',
            ]));
            $this->fail('the per-draw ceiling must refuse this bet');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_liability_exceeded', $e->errorCode());

            $context = $e->context();
            $this->assertSame('per_draw', $context['cap_scope']);
            $this->assertSame('100000.00', $context['cap']);
            $this->assertSame('108000.00', $context['would_be'], '90,000 + 18,000');
        }

        $this->assertSame('90000.00', $this->exposure()->reservedPotentialPayout($draw->getKey(), 'lane_game'));
        $this->assertNull(
            $this->exposure()->row($draw->getKey(), 'lane_game', '789', '3d_direct'),
            'a refused reservation must not leave a counter behind',
        );
    }

    public function test_a_multi_selection_reservation_is_all_or_nothing(): void
    {
        ['draw' => $draw] = $this->game();

        try {
            // First selection fits, second breaches: the whole call must roll back.
            $this->exposure()->reserve($draw->getKey(), 'lane_game', [
                ['number' => '123', 'bet_type' => '3d_direct', 'stake' => '10.00'],
                ['number' => '456', 'bet_type' => '3d_direct', 'stake' => '60.00'],
            ]);
            $this->fail('a breaching selection must refuse the whole reservation');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_liability_exceeded', $e->errorCode());
        }

        $this->assertSame('0.00', $this->exposure()->reservedPotentialPayout($draw->getKey(), 'lane_game'));
        $this->assertNull($this->exposure()->row($draw->getKey(), 'lane_game', '123', '3d_direct'));
    }

    public function test_exactly_reaching_the_ceiling_is_allowed_and_one_satang_more_is_not(): void
    {
        ['draw' => $draw] = $this->game('lane_game', ['max_liability_per_number' => '9000.00']);

        // Exactly 9,000: allowed, because a cap is a bound and not a strict inequality.
        $this->exposure()->reserve($draw->getKey(), 'lane_game', $this->one([
            'number' => '123', 'bet_type' => '3d_direct', 'stake' => '10.00',
        ]));

        $this->assertSame('9000.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));

        try {
            $this->exposure()->reserve($draw->getKey(), 'lane_game', $this->one([
                'number' => '123', 'bet_type' => '3d_direct', 'stake' => '0.01',
            ]));
            $this->fail('one satang past the ceiling must be refused');
        } catch (LotteryGameException $e) {
            $this->assertSame('per_number', $e->context()['cap_scope']);
            $this->assertSame('9009.00', $e->context()['would_be']);
        }
    }

    public function test_headroom_reports_both_ceilings_and_the_worst_numbers(): void
    {
        ['draw' => $draw] = $this->game();

        $this->exposure()->reserve($draw->getKey(), 'lane_game', [
            ['number' => '123', 'bet_type' => '3d_direct', 'stake' => '10.00'],
            ['number' => '45', 'bet_type' => '2d_bottom', 'stake' => '100.00'],
        ]);

        $headroom = $this->exposure()->headroom($draw->getKey(), 'lane_game');

        $this->assertSame('50000.00', $headroom['per_number_cap']);
        $this->assertSame('100000.00', $headroom['per_draw_cap']);
        $this->assertSame('18000.00', $headroom['draw_reserved']);
        $this->assertSame('82000.00', $headroom['draw_remaining']);

        $this->assertSame('9000.00', $headroom['numbers']['3d_direct/123']['reserved']);
        $this->assertSame('41000.00', $headroom['numbers']['3d_direct/123']['remaining']);
        $this->assertSame('9000.00', $headroom['numbers']['2d_bottom/45']['reserved']);
        $this->assertSame('41000.00', $headroom['numbers']['2d_bottom/45']['remaining']);
    }

    // =========================================================================
    // Releasing
    // =========================================================================

    public function test_release_gives_the_liability_back(): void
    {
        ['draw' => $draw] = $this->game();

        $selection = $this->one(['number' => '123', 'bet_type' => '3d_direct', 'stake' => '10.00']);

        $this->exposure()->reserve($draw->getKey(), 'lane_game', $selection);

        // The caller names the amount it recorded at placement. The service does NOT
        // recompute it from the current configuration: a multiplier edit between
        // placement and cancellation must not change what is given back.
        $released = $this->exposure()->release($draw->getKey(), 'lane_game', $this->one([
            'number' => '123', 'bet_type' => '3d_direct', 'stake' => '10.00', 'potential_payout' => '9000.00',
        ]));

        $this->assertSame('0.00', $released['draw_potential_payout']);
        $this->assertSame('0.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));

        // A row exists only while an exposure exists.
        $this->assertNull($this->exposure()->row($draw->getKey(), 'lane_game', '123', '3d_direct'));
    }

    public function test_a_release_that_names_no_amount_releases_nothing(): void
    {
        ['draw' => $draw] = $this->game();

        $this->exposure()->reserve($draw->getKey(), 'lane_game', $this->one([
            'number' => '123', 'bet_type' => '3d_direct', 'stake' => '10.00',
        ]));

        // Deliberately fail-safe: an amount-less release subtracts zero rather than
        // recomputing from configuration. Stranding liability is recoverable by an
        // operator; silently releasing MORE than was reserved is not.
        $this->exposure()->release($draw->getKey(), 'lane_game', $this->one([
            'number' => '123', 'bet_type' => '3d_direct', 'stake' => '10.00',
        ]));

        $this->assertSame('9000.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));
    }

    public function test_release_is_idempotent_and_never_goes_negative(): void
    {
        ['draw' => $draw] = $this->game();

        $selection = $this->one(['number' => '123', 'bet_type' => '3d_direct', 'stake' => '10.00']);

        $this->exposure()->reserve($draw->getKey(), 'lane_game', $selection);

        $cancellation = $this->one([
            'number' => '123', 'bet_type' => '3d_direct', 'stake' => '10.00', 'potential_payout' => '9000.00',
        ]);

        $this->exposure()->release($draw->getKey(), 'lane_game', $cancellation);

        // A cancellation replayed by a retry, or a double-click, must not owe the operator
        // money it never reserved.
        $this->exposure()->release($draw->getKey(), 'lane_game', $cancellation);

        $this->assertSame('0.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));
        $this->assertSame('0.00', $this->exposure()->reservedPotentialPayout($draw->getKey(), 'lane_game'));
    }

    public function test_release_returns_only_what_was_reserved(): void
    {
        ['draw' => $draw] = $this->game();

        $this->exposure()->reserve($draw->getKey(), 'lane_game', $this->one([
            'number' => '123', 'bet_type' => '3d_direct', 'stake' => '10.00',
        ]));

        // Releasing a larger amount than was reserved would credit the draw; the counter
        // must stop at zero, not invert.
        $this->exposure()->release($draw->getKey(), 'lane_game', $this->one([
            'number' => '123', 'bet_type' => '3d_direct', 'stake' => '10.00', 'potential_payout' => '999999.00',
        ]));

        $this->assertSame('0.00', $this->exposure()->exposureFor($draw->getKey(), 'lane_game', '123', '3d_direct'));
    }

    // =========================================================================
    // Fail-closed
    // =========================================================================

    public function test_an_unconfigured_ceiling_refuses_rather_than_meaning_unlimited(): void
    {
        ['draw' => $draw] = $this->game('lane_game', ['max_liability_per_number' => null]);

        try {
            $this->exposure()->reserve($draw->getKey(), 'lane_game', $this->one([
                'number' => '123', 'bet_type' => '3d_direct', 'stake' => '1.00',
            ]));
            $this->fail('an unconfigured ceiling must refuse');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_not_configured', $e->errorCode());
            $this->assertContains('max_liability_per_number', $e->context()['missing']);
        }

        $this->assertSame(0, GameNumberExposure::query()->count());
    }

    public function test_a_selection_of_the_wrong_width_is_refused(): void
    {
        ['draw' => $draw] = $this->game();

        try {
            $this->exposure()->reserve($draw->getKey(), 'lane_game', $this->one([
                'number' => '1234', 'bet_type' => '3d_direct', 'stake' => '1.00',
            ]));
            $this->fail('a four-digit selection in a three-digit market must be refused');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_number_invalid', $e->errorCode());
        }
    }

    public function test_a_non_digit_selection_is_refused(): void
    {
        ['draw' => $draw] = $this->game();

        try {
            $this->exposure()->reserve($draw->getKey(), 'lane_game', $this->one([
                'number' => '12a', 'bet_type' => '3d_direct', 'stake' => '1.00',
            ]));
            $this->fail('a non-digit selection must be refused');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_number_invalid', $e->errorCode());
        }
    }

    public function test_a_market_the_game_does_not_sell_is_refused(): void
    {
        ['draw' => $draw] = $this->game();

        try {
            $this->exposure()->reserve($draw->getKey(), 'lane_game', $this->one([
                'number' => '123', 'bet_type' => '4d_direct', 'stake' => '1.00',
            ]));
            $this->fail('an undeclared bet type must be refused');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_number_invalid', $e->errorCode());
        }
    }

    public function test_an_empty_reservation_is_a_caller_defect(): void
    {
        ['draw' => $draw] = $this->game();

        try {
            $this->exposure()->reserve($draw->getKey(), 'lane_game', []);
            $this->fail('an empty reservation must be refused');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_release_refused', $e->errorCode());
        }
    }

    public function test_reserving_for_an_undeclared_game_is_refused(): void
    {
        ['draw' => $draw] = $this->game();

        try {
            $this->exposure()->reserve($draw->getKey(), 'no_such_game', $this->one([
                'number' => '123', 'bet_type' => '3d_direct', 'stake' => '1.00',
            ]));
            $this->fail('an unknown game cannot hold liability');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_unknown', $e->errorCode());
        }
    }
}
