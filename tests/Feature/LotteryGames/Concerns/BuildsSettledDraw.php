<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames\Concerns;

use App\DTOs\LotteryGames\DigitBetSelection;
use App\DTOs\LotteryGames\DigitDrawResult;
use App\DTOs\LotteryGames\GameDefinition;
use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LotteryGames\GameBetPlacementService;
use App\Services\LotteryGames\GameRegistry;
use App\Services\LotteryGames\GameResultIngestionService;
use App\Services\LotteryGames\GameSettlementAdapter;

/**
 * One fixture for every suite that settles a digit draw.
 *
 * WHY IT LIVES HERE AND NOT IN GameLaneTestCase
 * GameLaneTestCase is about what it takes to SELL a digit bet: a funded player, an open
 * draw, a provable limit. Settlement needs three things that are actively wrong for a
 * placement test - the draw closed, the result published through the four-eyes boundary,
 * and the payout safety mode armed - so putting them in the placement fixture would arm
 * live prize payouts for tests that never asked for money to move.
 *
 * WHY IT IS SHARED AT ALL
 * SettlementTest and SettlementIdempotencyTest must describe the SAME draw. If each had
 * its own copy of "close the draw, publish the digits, arm the mode", the idempotency
 * suite could start passing against a draw that the settlement suite would refuse, and the
 * pair of suites would stop proving anything about each other.
 *
 * THE FIFTH THING IT PINS: THE PUBLICATION BRIDGE
 * Batch 1 canonicalises first_prize as SIX digits, and the 3d_direct market reads
 * first_prize_last_three from it. A three-digit game therefore has to land its digits in
 * the LAST three of that field, which is what 'digits_left_padded' does ('123' ->
 * '000123'). The shared lane fixture pads on the right instead ('123000'), which is right
 * for a game whose markets read the FRONT of the number and would settle every 3d_direct
 * bet here against '000'. The bridge is therefore part of this fixture, stated once.
 */
trait BuildsSettledDraw
{
    protected const SETTLEMENT_DRAW_NO = 'LANE-20260401-2100';

    protected function adapter(): GameSettlementAdapter
    {
        return app(GameSettlementAdapter::class);
    }

    protected function placement(): GameBetPlacementService
    {
        return app(GameBetPlacementService::class);
    }

    protected function ingestion(): GameResultIngestionService
    {
        return app(GameResultIngestionService::class);
    }

    protected function definition(string $code = 'lane_game'): GameDefinition
    {
        return app(GameRegistry::class)->definition($code);
    }

    /**
     * A sellable lane with one funded player, a limit for every number the test touches,
     * and the draw number the provider will later report.
     *
     * THE DRAW IS STILL OPEN HERE, ON PURPOSE. Bets can only be bought inside the betting
     * window, so a fixture that closed the draw first could never place one. Closing is a
     * separate, explicit step (closeDraw()) because "the window shut" is itself part of
     * what makes a result publishable.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{game: \App\Models\LotteryGame, draw: Draw, user: User, wallet: Wallet, maker: User, checker: User}
     */
    protected function ready(array $overrides = []): array
    {
        // THE BRIDGE IS OVERRIDDEN HERE, AND WHY THAT IS THE POINT RATHER THAN A FUDGE
        // Batch 1 canonicalises `first_prize` as SIX digits and its 3d_direct market reads
        // `first_prize_last_three` from it. A three-digit game therefore has to land its
        // digits in the LAST three of that six-digit field, which is what
        // 'digits_left_padded' does ('123' -> '000123'); the shared lane fixture pads on the
        // right instead ('123000'), which is right for a game whose markets read the FRONT of
        // the number and wrong for this one. Left as-is it would settle every 3d_direct bet
        // against '000' and pay nobody - which is exactly the failure the bridge exists to
        // make explicit. So the settlement suite states the projection that matches the
        // markets it settles through, and asserts the money that projection produces.
        $lane = $this->openLane('lane_game', array_merge([
            'publication_bridge' => [
                'first_prize' => 'digits_left_padded',
                'bottom_two' => 'last:2',
            ],
        ], $overrides));

        $lane['draw']->forceFill(['draw_number' => self::SETTLEMENT_DRAW_NO])->save();

        $player = $this->player('1000.00');

        foreach ([['3d_direct', '123'], ['3d_direct', '456'], ['2d_bottom', '23']] as [$market, $number]) {
            $this->limitFor($lane['draw'], $market, $number);
        }

        $maker = User::factory()->create();
        config()->set('lottery_games.ingestion.maker_user_id', (int) $maker->getKey());

        // SETTLEMENT PAYS NOBODY UNTIL THIS IS LIVE, AND THAT IS NOT A TEST BUG.
        // Batch 1 ships finance.prize_payout.safety_mode = DISABLED: settlement computes
        // the win, creates the obligation (a Pending payout) and suppresses the credit
        // until an operator arms the live mode. A suite that asserted credits without
        // arming it would be asserting against a platform that is behaving correctly.
        // Arm it here, and see the dedicated safety-mode test below for the other side.
        config()->set('finance.prize_payout.safety_mode', 'LIVE');
        // 9000.00 is under the default 1000000.00 auto-approval ceiling, so the prize is
        // credited immediately instead of waiting for a second pair of eyes. Named
        // explicitly so a change to the shipped default cannot silently turn every
        // winner below into a pending approval.
        config()->set('finance.prize_payout.auto_approve_below', '1000000.00');

        return [
            'game' => $lane['game'],
            'draw' => $lane['draw']->fresh(),
            'user' => $player['user'],
            'wallet' => $player['wallet'],
            'maker' => $maker,
            'checker' => User::factory()->create(),
        ];
    }

    /**
     * Buy one digit bet, through the lane's own placement service.
     *
     * @return array<string, mixed> the placement receipt
     */
    protected function bet(
        User $user,
        Draw $draw,
        string $betType,
        string $number,
        string $stake,
        string $seed,
    ): array {
        return $this->placement()->place(
            userId: (int) $user->getKey(),
            selection: new DigitBetSelection('lane_game', $betType, $number, $stake),
            drawId: (int) $draw->getKey(),
            idempotencyKey: $this->key($seed),
        );
    }

    /**
     * The draw closes: betting stops and the number is fixed.
     *
     * This is the state a real draw enters when its window elapses - DrawStatus::Drawing
     * carries Batch 1's ResultPending lifecycle state, which is the ONLY state from which
     * a result may be published. Reaching it any other way (writing the result rows
     * directly) is refused by ResultWriteBoundaryTest's boundary, not here.
     */
    protected function closeDraw(Draw $draw): Draw
    {
        $draw->forceFill([
            'status' => DrawStatus::Drawing->value,
            'closed_at' => now()->subMinutes(10),
            'betting_close_at' => now()->subMinutes(20),
        ])->save();

        return $draw->fresh();
    }

    /**
     * Publish the drawn digits through staging plus the four-eyes boundary.
     *
     * @return array<string, mixed> the publication outcome
     */
    protected function publishDigits(string $digits, ?Draw $draw = null): array
    {
        $draw = $this->closeDraw($draw ?? Draw::query()->firstOrFail());

        $staged = $this->ingestion()->stage(
            $this->definition(),
            new DigitDrawResult(
                gameCode: 'lane_game',
                drawNo: (string) $draw->draw_number,
                digits: $digits,
                drawnAt: now()->toIso8601String(),
                source: 'official',
            ),
        );

        $checker = User::query()->whereKeyNot((int) config('lottery_games.ingestion.maker_user_id'))->firstOrFail();

        return $this->ingestion()->publish((int) $staged['import_id'], (int) $checker->getKey(), 'checked against the feed');
    }

    protected function balanceOf(Wallet $wallet): string
    {
        return bcadd((string) $wallet->fresh()->balance, '0', 2);
    }
}
