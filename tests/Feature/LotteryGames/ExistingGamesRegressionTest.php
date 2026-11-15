<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\DTOs\Betting\BetCancellationData;
use App\Enums\BetCancellationReason;
use App\Enums\BetStatus;
use App\Events\BetCancelled;
use App\Models\Bet;
use App\Models\GameBetAttribution;
use App\Models\GameNumberExposure;
use App\Models\GameResultImport;
use App\Models\LotteryGame;
use App\Models\Payout;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Betting\BetCancellationService;
use App\Services\LotteryGames\GameRegistry;
use Database\Seeders\LotteryGamesSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Api\V1\ApiPurchaseTestCase;

/**
 * Batch 2 must be INVISIBLE to everything Batch 1 already sells.
 *
 * WHY THIS IS THE SUITE THAT DECIDES WHETHER THE BATCH MAY SHIP
 * Every other file in this lane proves the new behaviour works. None of them proves the
 * old behaviour still does. The batch touches things that every existing product runs
 * through - the bets table, the draw lifecycle, the cancellation path, the API route
 * group, the settlement pipeline - and each of those edits is a chance to break a product
 * that has nothing to do with digit games. So this file runs the ORDINARY lane (a plain
 * 3D purchase and a plain cancellation, through Batch 1's own API and service) with the
 * whole digit-game batch installed, and asserts that nothing about it changed.
 *
 * WHAT "NOTHING CHANGED" MEANS, CONCRETELY
 *   the purchase            201, one bet, one stake debited, the same price as before.
 *   the rows                the digit lane writes NO attribution and NO exposure for an
 *                           ordinary slip, and the new bets columns stay NULL.
 *   the tariff              the shipped market multipliers are the numbers Batch 1 has
 *                           always used. A batch that quietly repriced 3D would be the
 *                           worst possible way to fund a new game.
 *   the schema              the three new tables exist, and the existing tables still have
 *                           the columns the old code reads.
 *   the routes              the digit routes do not shadow the existing purchase route.
 *   the seeder              re-running it changes no operator decision, creates no
 *                           duplicates, and never enables a game.
 *   the command             `lottery-games:validate-rules` is read-only.
 *   the cancellation        an ordinary cancellation still refunds, and now announces
 *                           BetCancelled exactly once - which is how the digit lane learns
 *                           to give its reserved liability back. The listeners must be
 *                           no-ops for a bet that belongs to no game.
 *
 * THE CANCELLATION ASSERTION IS THE ONE THAT COULD BITE
 * BetCancellationService::cancel() now dispatches an event that two Batch-2 listeners
 * handle. Those listeners run for EVERY cancellation on the platform, including bets that
 * predate the batch and bets that belong to no game at all. If either of them threw, an
 * ordinary player's refund would turn into a 500 - so the ordinary path is asserted here
 * to be a silent no-op rather than assumed to be one.
 */
final class ExistingGamesRegressionTest extends ApiPurchaseTestCase
{
    private const PURCHASE_URI = '/api/v1/bets/purchase';

    /**
     * Install the digit lane the way a deployment does: switch on, seed the shipped games,
     * and let the registry re-read configuration.
     */
    private function installDigitLane(): void
    {
        config()->set('lottery_games.master_enabled', true);

        $this->seed(LotteryGamesSeeder::class);

        $this->app->forgetInstance(GameRegistry::class);
    }

    /**
     * Buy one ordinary slip through Batch 1's own API.
     *
     * @param  array{user: User, wallet: Wallet, draw: \App\Models\Draw}  $fixture
     * @return array<string, mixed> the decoded purchase response
     */
    private function purchase(array $fixture, string $market = '3d_direct', string $number = '123', string $stake = '10.00'): array
    {
        $this->ensureLimit($fixture['draw'], $market, $number);

        $response = $this->actingAs($fixture['user'], 'sanctum')->postJson(
            self::PURCHASE_URI,
            $this->payload($fixture['draw'], $market, $number, $stake, $this->key('regression-'.$market)),
        );

        $response->assertStatus(201);

        return (array) $response->json();
    }

    // =========================================================================
    // The ordinary purchase
    // =========================================================================

    public function test_an_ordinary_purchase_still_works_with_the_digit_lane_installed(): void
    {
        $this->installDigitLane();

        $fixture = $this->fixture('1000.00');

        $body = $this->purchase($fixture);

        $this->assertTrue((bool) $body['success']);

        // One bet, one stake, one price - exactly as before the batch.
        $this->assertSame(1, Bet::query()->count());
        $this->assertSame('990.00', bcadd((string) $fixture['wallet']->fresh()->balance, '0', 2));

        $bet = Bet::query()->firstOrFail();
        $this->assertSame((int) $fixture['user']->getKey(), (int) $bet->user_id);
        $this->assertSame(BetStatus::Active, $bet->status);
    }

    public function test_an_ordinary_purchase_writes_nothing_belonging_to_the_digit_lane(): void
    {
        $this->installDigitLane();

        $fixture = $this->fixture('1000.00');

        $this->purchase($fixture);

        // No attribution, no exposure, no import: a 3D slip is a 3D slip, not a game bet
        // wearing a game's bookkeeping.
        $this->assertSame(0, GameBetAttribution::query()->count());
        $this->assertSame(0, GameNumberExposure::query()->count());
        $this->assertSame(0, GameResultImport::query()->count());

        // The columns the batch added to `bets` are optional by construction, and stay NULL
        // for a bet that has nothing to do with a game.
        $bet = Bet::query()->firstOrFail();

        $this->assertNull($bet->game_code);
        $this->assertNull($bet->game_number);
        $this->assertNull($bet->game_bet_type);
        $this->assertNull($bet->free_bet_voucher_id);
    }

    public function test_the_shipped_market_tariff_is_untouched(): void
    {
        $this->installDigitLane();

        // Pinned on purpose. These are the multipliers Batch 1 has always paid, and a batch
        // that needed to fund itself by quietly changing them would be a repricing scandal,
        // not a feature.
        $this->assertSame(900, (int) config('lottery.markets.3d_direct.payout_multiplier'));
        $this->assertSame(90, (int) config('lottery.markets.2d_bottom.payout_multiplier'));

        // And the digit lane's own switch does not leak into the market table: no game code
        // ever becomes a market a client could buy directly.
        $this->assertNull(config('lottery.markets.lane_game'));
        $this->assertNull(config('lottery.markets.speed_88'));

        $markets = (array) config('lottery.markets');
        $this->assertArrayHasKey('3d_direct', $markets);
        $this->assertArrayHasKey('2d_bottom', $markets);
        $this->assertSame(6, count($markets), 'the shipped market catalogue is exactly what Batch 1 ships');
    }

    public function test_the_batch_added_tables_without_removing_anything_the_old_code_reads(): void
    {
        foreach (['lottery_games', 'game_result_imports', 'game_number_exposures'] as $table) {
            $this->assertTrue(Schema::hasTable($table), 'the batch owns '.$table);
        }

        // The existing tables keep the columns Batch 1 reads. This is a spot check rather
        // than a full schema dump: the point is that the batch is ADDITIVE, and the columns
        // named here are the ones its own code touches.
        $this->assertTrue(Schema::hasColumns('bets', ['id', 'user_id', 'draw_id', 'stake_amount', 'status', 'metadata']));
        $this->assertTrue(Schema::hasColumns('draws', ['id', 'status', 'draw_number', 'type']));
        $this->assertTrue(Schema::hasColumns('wallets', ['id', 'user_id', 'balance', 'currency']));
        $this->assertTrue(Schema::hasColumns('payouts', ['id', 'bet_id', 'draw_id', 'amount', 'status']));
    }

    // =========================================================================
    // Routes
    // =========================================================================

    public function test_the_digit_routes_do_not_shadow_the_existing_purchase_route(): void
    {
        $this->installDigitLane();

        // /api/v1/games and /api/v1/games/{code} are new literal prefixes, and the existing
        // purchase route is untouched: a purchase still reaches Batch 1's controller.
        $this->assertNotNull(route('api.v1.bets.purchase'));

        $action = app('router')->getRoutes()->match(
            \Illuminate\Http\Request::create('/api/v1/bets/purchase', 'POST'),
        )->getActionName();

        $this->assertStringNotContainsString('Game', $action, 'the purchase route still belongs to Batch 1');
        $this->assertStringContainsString('Bet', $action);

        // And no existing route names a game: the batch added routes, it did not re-point one.
        $this->assertStringStartsWith('/api/v1/games', (string) route('api.v1.games.index', absolute: false));
    }

    // =========================================================================
    // Deployment tooling
    // =========================================================================

    public function test_the_seeder_is_idempotent_and_never_enables_a_game(): void
    {
        $this->installDigitLane();

        $afterFirst = LotteryGame::query()->count();
        $this->assertGreaterThan(0, $afterFirst, 'the shipped games are registered');

        // Running the deployment seeder again must not duplicate rows, and must not turn an
        // unverified game into a sellable one: verification is an audited operator act.
        $this->seed(LotteryGamesSeeder::class);

        $this->assertSame($afterFirst, LotteryGame::query()->count(), 'no duplicate game rows');

        foreach (LotteryGame::query()->get() as $game) {
            $this->assertFalse((bool) $game->enabled, $game->code.' is not enabled by a seeder');
            $this->assertNull($game->verified_at, $game->code.' is not verified by a seeder');
        }
    }

    public function test_seeding_the_lane_does_not_touch_existing_draws_or_bets(): void
    {
        $fixture = $this->fixture('1000.00');

        $purchased = $this->purchase($fixture);

        $drawBefore = (array) \App\Models\Draw::query()->findOrFail($fixture['draw']->getKey())->getAttributes();
        $betBefore = (array) Bet::query()->findOrFail($purchased['data']['bet']['id'] ?? Bet::query()->firstOrFail()->getKey())->getAttributes();

        $this->installDigitLane();

        $this->assertSame(
            $drawBefore,
            (array) \App\Models\Draw::query()->findOrFail($fixture['draw']->getKey())->getAttributes(),
            'seeding the digit lane must not rewrite an existing draw',
        );

        $this->assertSame(
            $betBefore,
            (array) Bet::query()->whereKey(Bet::query()->firstOrFail()->getKey())->firstOrFail()->getAttributes(),
            'nor an existing bet',
        );
    }

    public function test_the_rules_validator_command_changes_nothing(): void
    {
        $this->installDigitLane();

        $before = LotteryGame::query()->orderBy('code')->get(['code', 'status', 'enabled', 'rules_checksum', 'verified_at'])->toArray();

        // The shipped rules are full of REQUIRED_INPUT, so the validator must refuse. What
        // matters here is that refusing is all it does: a validator that "fixed" a game to
        // make itself pass would be the most dangerous command in the deployment.
        $exit = $this->artisan('lottery-games:validate-rules')->run();

        $this->assertSame(1, $exit, 'a game with missing inputs is not validated');

        $this->assertSame(
            $before,
            LotteryGame::query()->orderBy('code')->get(['code', 'status', 'enabled', 'rules_checksum', 'verified_at'])->toArray(),
            'the validator is read-only',
        );
    }

    // =========================================================================
    // The cancellation seam: every cancellation in the platform now runs two listeners
    // =========================================================================

    public function test_an_ordinary_cancellation_still_refunds_and_announces_itself_once(): void
    {
        $this->installDigitLane();

        $fixture = $this->fixture('1000.00');
        $purchased = $this->purchase($fixture);

        $betId = (int) (Bet::query()->firstOrFail()->getKey());

        /** @var list<BetCancelled> $announced */
        $announced = [];
        Event::listen(BetCancelled::class, static function (BetCancelled $event) use (&$announced): void {
            $announced[] = $event;
        });

        $result = app(BetCancellationService::class)->cancel(new BetCancellationData(
            userId: (int) $fixture['user']->getKey(),
            betIdentifier: (string) $betId,
            reason: BetCancellationReason::PlayerRequest,
            clientKey: $this->key('regression-cancel'),
        ));

        // 1. The ordinary refund is unchanged: money back, slip cancelled.
        $this->assertSame('10.00', bcadd((string) $result->refundAmount, '0', 2));
        $this->assertSame(BetStatus::Cancelled, Bet::query()->findOrFail($betId)->status);
        $this->assertSame('1000.00', bcadd((string) $fixture['wallet']->fresh()->balance, '0', 2));

        // 2. The cancellation announced itself exactly once, with the recorded reason.
        $this->assertCount(1, $announced, 'one cancellation, one announcement');
        $this->assertSame($betId, (int) $announced[0]->bet->getKey());
        $this->assertSame('player_request', $announced[0]->reason);

        // 3. The digit lane's listeners saw a bet that belongs to no game and did nothing -
        //    no exposure to release, no attribution to write, and no exception that could
        //    have turned this refund into a 500.
        $this->assertSame(0, GameBetAttribution::query()->count());
        $this->assertSame(0, GameNumberExposure::query()->count());
        $this->assertSame(0, Payout::query()->count());
        $this->assertNull(Bet::query()->findOrFail($betId)->game_code, 'the slip still belongs to no game');
        $this->assertTrue((bool) $purchased['success'], 'the purchase it cancelled was an ordinary one');
    }

    public function test_a_replayed_cancellation_does_not_announce_twice(): void
    {
        $this->installDigitLane();

        $fixture = $this->fixture('1000.00');
        $this->purchase($fixture);

        $betId = (int) Bet::query()->firstOrFail()->getKey();

        $service = app(BetCancellationService::class);
        $data = new BetCancellationData(
            userId: (int) $fixture['user']->getKey(),
            betIdentifier: (string) $betId,
            reason: BetCancellationReason::PlayerRequest,
            clientKey: $this->key('regression-cancel-twice'),
        );

        $first = $service->cancel($data);

        /** @var list<BetCancelled> $announced */
        $announced = [];
        Event::listen(BetCancelled::class, static function (BetCancelled $event) use (&$announced): void {
            $announced[] = $event;
        });

        // A retried cancellation is a replay: the money is not refunded again, and - because
        // the dispatch sits after the committed work rather than in it - nothing is announced
        // again either. A double announcement would release the same reserved liability twice.
        $second = $service->cancel($data);

        $this->assertTrue($second->isReplay());
        $this->assertSame('10.00', bcadd((string) $second->refundAmount, '0', 2));
        $this->assertCount(0, $announced, 'a replay announces nothing');
        $this->assertSame('1000.00', bcadd((string) $fixture['wallet']->fresh()->balance, '0', 2));
        $this->assertSame($first->refund->getKey(), $second->refund->getKey(), 'the same refund, replayed');
    }
}
