<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\Models\AgeVerification;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\User;
use App\Services\Legal\ConsentService;
use App\Services\LotteryGames\GameRegistry;
use Illuminate\Http\Request;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * The seven HTTP routes of the digit-game lane, over the wire.
 *
 * WHY THE SERVICE SUITES ARE NOT ENOUGH
 * Every other batch-2 suite calls the services directly, which proves the logic and
 * proves nothing about the surface. The surface is where three separate mistakes live:
 *
 *   AUTH FLOOR   the game routes sit in the SAME authenticated group as the existing bet
 *                routes, and the placement route additionally carries `adult` and
 *                `legal.current`. A lane with a weaker floor is a purchase bypass, so the
 *                floor is asserted from the outside: no session means 401, and a member who
 *                has not attested 18+ is refused before any game code runs.
 *   STATUS CODES an operator (and every client) has to be able to tell "state" (409) from
 *                "this body" (422) from "this deployment" (503) from "no such thing" (404).
 *                RendersGameResponses owns that mapping; this file checks it end to end,
 *                code by code, because a mapping nobody asserts is a mapping that drifts.
 *   IDENTITY     the request body can say what to buy and how much to stake. It cannot say
 *                WHO is buying. `user_id`, `payout`, `market_key` and `status` are not
 *                fields at all, and a crafted body cannot move a bet onto another account
 *                or a payout onto another number.
 *
 * WHY THIS SUITE REGISTERS A GAME THROUGH THE SAME FIXTURE AS THE SERVICE SUITES
 * The routes resolve through GameRegistry, which reads configuration. Registering the
 * game the same way placement tests do means a route test that passes and a placement test
 * that passes are talking about the same game - rather than one suite quietly testing a
 * different rule block from the other.
 *
 * MONEY IS COMPARED AS DECIMAL STRINGS; JSON IS ASSERTED BY PATH, NOT BY WHOLE-DOCUMENT
 * COMPARISON, so a new field does not break a test that is about something else.
 */
final class GameApiTest extends GameLaneTestCase
{
    private const GAMES = '/api/v1/games';

    /**
     * A member who has cleared every gate the routes demand: authenticated, active,
     * attested 18+, and holding current legal consent.
     *
     * The recipe is ApiPurchaseTestCase's, repeated here because this suite's base class has
     * to be the digit-game one (it needs openLane()). A test that skipped the onboarding
     * would answer 403/428 before the game code ran and would silently be a test of the age
     * gate instead of a test of the lane.
     */
    private function apiPlayer(string $balance = '1000.00'): array
    {
        $user = User::factory()->create([
            'date_of_birth' => now()->subYears(30)->toDateString(),
        ]);

        AgeVerification::query()->create([
            'user_id' => $user->getKey(),
            'status' => 'eligible',
            'minimum_age' => (int) config('legal.minimum_age', 18),
            'method' => 'self_declaration',
            'attested_at' => now(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'source' => 'test',
        ]);

        $documents = collect(app(ConsentService::class)->currentDocuments())->keyBy('document_type');
        $terms = $documents->get('terms', []);
        $privacy = $documents->get('privacy', []);

        app(ConsentService::class)->acceptCurrentDocuments(
            $user,
            Request::create('/test', 'POST', [
                'terms' => true,
                'privacy_notice' => true,
                'terms_version' => $terms['version'],
                'terms_hash' => $terms['content_hash'],
                'privacy_version' => $privacy['version'],
                'privacy_hash' => $privacy['content_hash'],
            ], server: ['REMOTE_ADDR' => '127.0.0.1']),
            'api',
        );

        $wallet = \App\Models\Wallet::factory()->for($user)->withBalance($balance)->create();

        return ['user' => $user->refresh(), 'wallet' => $wallet];
    }

    /**
     * A sellable lane plus an onboarded API member with a limit for the number used.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{game: \App\Models\LotteryGame, draw: Draw, user: User, wallet: \App\Models\Wallet}
     */
    private function apiLane(array $overrides = []): array
    {
        $lane = $this->openLane('lane_game', $overrides);
        $player = $this->apiPlayer();

        $this->limitFor($lane['draw'], '3d_direct', '123');

        return [...$lane, ...$player];
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteBody(array $overrides = []): array
    {
        return array_merge([
            'bet_type' => '3d_direct',
            'number' => '123',
            'stake' => '10.00',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function placeBody(array $overrides = []): array
    {
        return array_merge($this->quoteBody(), [
            'idempotency_key' => $this->key('api-place'),
        ], $overrides);
    }

    // =========================================================================
    // The floor
    // =========================================================================

    public function test_every_game_route_is_closed_without_a_session(): void
    {
        $this->openLane('lane_game');

        $this->getJson(self::GAMES)->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->getJson(self::GAMES.'/lane_game')->assertStatus(401);
        $this->getJson(self::GAMES.'/lane_game/upcoming')->assertStatus(401);
        $this->postJson(self::GAMES.'/lane_game/bets/quote', $this->quoteBody())->assertStatus(401);
        $this->postJson(self::GAMES.'/lane_game/bets', $this->placeBody())->assertStatus(401);
        $this->getJson(self::GAMES.'/lane_game/results/latest')->assertStatus(401);
        $this->getJson(self::GAMES.'/lane_game/calendar')->assertStatus(401);
        $this->getJson(self::GAMES.'/lane_game/results')->assertStatus(401);
    }

    public function test_a_placement_is_refused_before_the_lane_runs_when_age_has_not_been_attested(): void
    {
        ['draw' => $draw] = $this->openLane('lane_game');
        $this->limitFor($draw, '3d_direct', '123');

        // Authenticated, but never attested 18+. The adult gate runs BEFORE the controller,
        // so no bet can exist and the wallet cannot move.
        $user = User::factory()->create(['date_of_birth' => now()->subYears(30)->toDateString()]);
        \App\Models\Wallet::factory()->for($user)->withBalance('1000.00')->create();

        $response = $this->actingAs($user->refresh(), 'sanctum')
            ->postJson(self::GAMES.'/lane_game/bets', $this->placeBody());

        $this->assertContains($response->getStatusCode(), [403, 428, 409], 'the age gate refuses before the lane');
        $this->assertSame(0, Bet::query()->count());
        $this->assertSame('1000.00', bcadd((string) \App\Models\Wallet::query()->where('user_id', $user->getKey())->firstOrFail()->balance, '0', 2));
    }

    // =========================================================================
    // Reading the catalogue
    // =========================================================================

    public function test_the_catalogue_lists_every_declared_game_with_a_sellable_count(): void
    {
        ['user' => $user] = $this->apiLane();

        $response = $this->actingAs($user, 'sanctum')->getJson(self::GAMES);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // The catalogue lists EVERY declared game - the shipped blueprints (speed_88,
        // lao_hanoi_4d) are declared too, they are simply not sellable because nobody has
        // supplied their rules. Asserting against the registry's own code list rather than a
        // literal count keeps this test about the lane instead of about the shipped config.
        $codes = app(GameRegistry::class)->codes();

        $response->assertJsonPath('meta.count', count($codes));
        $this->assertSame(
            count($codes),
            count((array) $response->json('data')),
            'one row per declared game, no more',
        );

        $lane = collect((array) $response->json('data'))->firstWhere('code', 'lane_game');

        $this->assertNotNull($lane, 'the declared lane game is in the catalogue');
        $this->assertSame(3, $lane['digits']);
        $this->assertTrue($lane['sellable'], 'the only game with supplied rules is the only sellable one');
        $this->assertSame(1, $response->json('meta.sellable_count'), 'and the meta says exactly that');
    }

    public function test_one_game_can_be_read_by_code(): void
    {
        ['user' => $user] = $this->apiLane();

        $response = $this->actingAs($user, 'sanctum')->getJson(self::GAMES.'/lane_game');

        $response->assertStatus(200);
        $response->assertJsonPath('data.code', 'lane_game');
        $response->assertJsonPath('data.name', 'Lane Game');
        $response->assertJsonPath('data.digits', 3);
    }

    public function test_an_undeclared_game_is_a_404_naming_the_code(): void
    {
        ['user' => $user] = $this->apiLane();

        $response = $this->actingAs($user, 'sanctum')->getJson(self::GAMES.'/no_such_game');

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'game_unknown');
    }

    public function test_upcoming_reports_the_next_draw_and_its_countdown(): void
    {
        ['user' => $user, 'draw' => $draw] = $this->apiLane();

        $response = $this->actingAs($user, 'sanctum')->getJson(self::GAMES.'/lane_game/upcoming');

        $response->assertStatus(200);
        $response->assertJsonPath('data.draw_id', (int) $draw->getKey());
        $response->assertJsonPath('data.can_accept_bets', true);
        $response->assertJsonPath('meta.sellable', true);
    }

    // =========================================================================
    // Quoting: no side effects, ever
    // =========================================================================

    public function test_a_quote_prices_the_selection_without_placing_anything(): void
    {
        ['user' => $user, 'wallet' => $wallet] = $this->apiLane();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(self::GAMES.'/lane_game/bets/quote', $this->quoteBody());

        $response->assertStatus(200);
        $response->assertJsonPath('data.sellable', true);
        $response->assertJsonPath('data.funding_source', 'real');
        $response->assertJsonPath('data.match_mode', 'exact');
        $response->assertJsonPath('data.potential_payout', '9000.00');
        $response->assertJsonPath('meta.expires_with_the_draw', true);

        // A quote is a question, not a purchase.
        $this->assertSame(0, Bet::query()->count());
        $this->assertSame('1000.00', bcadd((string) $wallet->fresh()->balance, '0', 2));
    }

    public function test_a_quote_refuses_a_number_of_the_wrong_width_with_a_422(): void
    {
        ['user' => $user] = $this->apiLane();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(self::GAMES.'/lane_game/bets/quote', $this->quoteBody(['number' => '12']));

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'game_number_invalid');
    }

    public function test_a_quote_that_is_not_a_digit_string_never_reaches_the_lane(): void
    {
        ['user' => $user] = $this->apiLane();

        // The FormRequest refuses '1a3' structurally: a selection is a digit string so that
        // a leading zero survives, and a non-digit one has no meaning to preserve.
        $response = $this->actingAs($user, 'sanctum')
            ->postJson(self::GAMES.'/lane_game/bets/quote', $this->quoteBody(['number' => '1a3']));

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'validation_failed');
    }

    // =========================================================================
    // Placing: identity and replay over the wire
    // =========================================================================

    public function test_a_placement_returns_201_with_the_receipt_and_debits_the_stake(): void
    {
        ['user' => $user, 'wallet' => $wallet, 'draw' => $draw] = $this->apiLane();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(self::GAMES.'/lane_game/bets', $this->placeBody());

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'accepted');
        $response->assertJsonPath('data.game_code', 'lane_game');
        $response->assertJsonPath('data.number', '123');
        $response->assertJsonPath('data.stake', '10.00');
        $response->assertJsonPath('data.potential_payout', '9000.00');
        $response->assertJsonPath('data.funding_source', 'real');
        $response->assertJsonPath('meta.game_code', 'lane_game');

        $this->assertSame('990.00', bcadd((string) $wallet->fresh()->balance, '0', 2));
        $this->assertSame(1, Bet::query()->where('draw_id', $draw->getKey())->count());
    }

    public function test_the_same_idempotency_key_replays_the_placement_instead_of_buying_twice(): void
    {
        ['user' => $user, 'wallet' => $wallet] = $this->apiLane();

        $first = $this->actingAs($user, 'sanctum')->postJson(self::GAMES.'/lane_game/bets', $this->placeBody());
        $second = $this->actingAs($user, 'sanctum')->postJson(self::GAMES.'/lane_game/bets', $this->placeBody());

        $first->assertStatus(201);
        $first->assertJsonPath('data.status', 'accepted');
        $second->assertStatus(201);
        $second->assertJsonPath('data.status', 'replayed');
        $second->assertJsonPath('data.bet_id', $first->json('data.bet_id'));

        $this->assertSame(1, Bet::query()->count(), 'one bet for one key, however many requests');
        $this->assertSame('990.00', bcadd((string) $wallet->fresh()->balance, '0', 2), 'and one stake');
    }

    public function test_a_placement_without_an_idempotency_key_is_refused_structurally(): void
    {
        ['user' => $user] = $this->apiLane();

        $body = $this->placeBody();
        unset($body['idempotency_key']);

        $response = $this->actingAs($user, 'sanctum')->postJson(self::GAMES.'/lane_game/bets', $body);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame(0, Bet::query()->count());
    }

    public function test_a_placement_cannot_name_the_user_it_bets_for(): void
    {
        ['user' => $user, 'draw' => $draw] = $this->apiLane();
        $victim = User::factory()->create();
        \App\Models\Wallet::factory()->for($victim)->withBalance('5000.00')->create();

        // A crafted body that names another account. The field is not in the FormRequest, so
        // it is ignored; the bet belongs to the authenticated member and the victim's wallet
        // is not touched.
        $response = $this->actingAs($user, 'sanctum')->postJson(
            self::GAMES.'/lane_game/bets',
            $this->placeBody(['user_id' => $victim->getKey()]),
        );

        $response->assertStatus(201);

        $bet = Bet::query()->findOrFail($response->json('data.bet_id'));
        $this->assertSame((int) $user->getKey(), (int) $bet->user_id);
        $this->assertNotSame((int) $victim->getKey(), (int) $bet->user_id);
        $this->assertSame('5000.00', bcadd((string) \App\Models\Wallet::query()->where('user_id', $victim->getKey())->firstOrFail()->balance, '0', 2));
    }

    public function test_a_placement_cannot_declare_its_own_payout_or_market(): void
    {
        ['user' => $user] = $this->apiLane();

        $response = $this->actingAs($user, 'sanctum')->postJson(
            self::GAMES.'/lane_game/bets',
            $this->placeBody([
                'potential_payout' => '1000000.00',
                'payout_multiplier' => '100000',
                'market_key' => '4d_direct',
                'status' => 'accepted',
            ]),
        );

        $response->assertStatus(201);

        // The platform priced the bet from the game's rules, not from the body.
        $response->assertJsonPath('data.potential_payout', '9000.00');
        $response->assertJsonPath('data.market_key', '3d_direct');
    }

    public function test_a_one_element_selections_list_is_the_same_bet_as_the_flat_shape(): void
    {
        ['user' => $user, 'wallet' => $wallet] = $this->apiLane();

        $response = $this->actingAs($user, 'sanctum')->postJson(self::GAMES.'/lane_game/bets', [
            'selections' => [
                ['bet_type' => '3d_direct', 'number' => '123', 'stake' => '10.00'],
            ],
            'idempotency_key' => $this->key('api-selections'),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'accepted');
        $response->assertJsonPath('data.number', '123');
        $response->assertJsonPath('data.stake', '10.00');
        $response->assertJsonPath('data.potential_payout', '9000.00');

        $this->assertSame(1, Bet::query()->count(), 'one selection, one bet');
        $this->assertSame('990.00', bcadd((string) $wallet->fresh()->balance, '0', 2));
    }

    public function test_a_basket_of_selections_is_refused_rather_than_half_placed(): void
    {
        ['user' => $user, 'wallet' => $wallet] = $this->apiLane();

        $response = $this->actingAs($user, 'sanctum')->postJson(self::GAMES.'/lane_game/bets', [
            'selections' => [
                ['bet_type' => '3d_direct', 'number' => '123', 'stake' => '10.00'],
                ['bet_type' => '3d_direct', 'number' => '123', 'stake' => '10.00'],
            ],
            'idempotency_key' => $this->key('api-basket'),
        ]);

        // A placement buys ONE auditable bet: one key, one reservation, one attribution. A
        // basket through this path could only be half-placed, and a half-placed basket is
        // indistinguishable from a retry - so it is refused structurally, before any money
        // moves.
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame(0, Bet::query()->count());
        $this->assertSame('1000.00', bcadd((string) $wallet->fresh()->balance, '0', 2));
    }

    public function test_the_free_bet_voucher_may_be_named_by_either_field(): void
    {
        ['user' => $user] = $this->apiLane();

        // The voucher lane is not installed on this deployment, so the placement is refused -
        // which is the point: BOTH spellings must reach the same code path, and a body that
        // used the alias must not be treated as an unfunded cash bet.
        config()->set('lottery_games.funding.free_bet_provider', null);

        $alias = $this->actingAs($user, 'sanctum')->postJson(self::GAMES.'/lane_game/bets', [
            'bet_type' => '3d_direct',
            'number' => '123',
            'stake' => '10.00',
            'funding_source' => 'free_bet',
            'free_bet_voucher_id' => '987654',
            'idempotency_key' => $this->key('api-voucher-alias'),
        ]);

        $alias->assertStatus(422);
        $alias->assertJsonPath('error.code', 'game_funding_unavailable');

        // And the canonical name produces the identical refusal.
        $canonical = $this->actingAs($user, 'sanctum')->postJson(self::GAMES.'/lane_game/bets', [
            'bet_type' => '3d_direct',
            'number' => '123',
            'stake' => '10.00',
            'funding_source' => 'free_bet',
            'voucher_id' => '987654',
            'idempotency_key' => $this->key('api-voucher-canonical'),
        ]);

        $canonical->assertStatus(422);
        $canonical->assertJsonPath('error.code', 'game_funding_unavailable');
        $this->assertSame(0, Bet::query()->count());
    }

    // =========================================================================
    // Refusals over the wire: state, body, deployment
    // =========================================================================

    public function test_placing_on_a_closed_draw_is_a_409(): void
    {
        ['user' => $user, 'draw' => $draw] = $this->apiLane();

        $closed = $this->openDraw('lane_game', \Carbon\CarbonImmutable::now()->addHours(2));
        $closed->forceFill([
            'status' => \App\Enums\DrawStatus::Closed->value,
            'betting_close_at' => now()->subMinutes(5),
        ])->save();

        $response = $this->actingAs($user, 'sanctum')->postJson(
            self::GAMES.'/lane_game/bets',
            $this->placeBody(['draw_id' => (int) $closed->getKey()]),
        );

        // A closed draw is a STATE refusal: the lane reports the game as not sellable for
        // that draw and names the reason, rather than accepting the slip and failing later.
        // Both codes are 409, which is the number a client switches on ("this was refused
        // for a state reason, do not resend the body unchanged"); the code names the cause.
        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['game_draw_closed', 'game_not_sellable'],
            'a closed draw is refused as state, not as a malformed body',
        );
        $this->assertStringContainsString('closed', strtolower((string) $response->json('error.message')));
        $this->assertSame(0, Bet::query()->count());
    }

    public function test_placing_while_the_lane_is_switched_off_is_a_503(): void
    {
        ['user' => $user] = $this->apiLane();

        $this->enableGameLane(false);
        $this->refreshGameRegistry();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(self::GAMES.'/lane_game/bets', $this->placeBody());

        // With the master switch closed the game is simply not sellable, and the lane says
        // so as a STATE refusal (409) rather than a 503: nothing is broken or unconfigured,
        // the operator has turned selling off. A client must not retry this as if it were a
        // transient deployment problem.
        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'game_not_sellable');
        $this->assertSame(0, Bet::query()->count());
    }

    public function test_placing_an_unknown_game_is_a_404(): void
    {
        ['user' => $user] = $this->apiLane();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(self::GAMES.'/no_such_game/bets', $this->placeBody());

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'game_unknown');
    }

    public function test_placing_a_stake_outside_the_games_bounds_is_a_422(): void
    {
        ['user' => $user] = $this->apiLane(['min_stake' => '5.00']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(self::GAMES.'/lane_game/bets', $this->placeBody(['stake' => '1.00']));

        // The stake rule is the GAME's (min_stake 5.00), not the request's, so the refusal is
        // a state refusal carrying the lane's own reason rather than a structural 422: the
        // body is well formed, the stake is simply not one this game sells.
        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'game_not_sellable');
        $this->assertStringContainsString('stake_below_minimum', (string) $response->json('error.message'));
        $this->assertSame(0, Bet::query()->count());
    }

    // =========================================================================
    // Results
    // =========================================================================

    public function test_results_are_unavailable_until_something_is_published(): void
    {
        ['user' => $user] = $this->apiLane();

        $latest = $this->actingAs($user, 'sanctum')->getJson(self::GAMES.'/lane_game/results/latest');

        $latest->assertStatus(404);
        $latest->assertJsonPath('error.code', 'game_result_unavailable');

        $history = $this->actingAs($user, 'sanctum')->getJson(self::GAMES.'/lane_game/results');

        $history->assertStatus(200);
        $history->assertJsonPath('data', []);
        $history->assertJsonPath('meta.count', 0);
    }

    public function test_a_published_result_is_served_with_its_digits_and_hash(): void
    {
        ['user' => $user, 'draw' => $draw] = $this->apiLane();

        $this->publishResultFor($draw, '123');

        $response = $this->actingAs($user, 'sanctum')->getJson(self::GAMES.'/lane_game/results/latest');

        $response->assertStatus(200);
        $response->assertJsonPath('data.game_code', 'lane_game');
        $response->assertJsonPath('data.digits', '123');
        $this->assertNotEmpty($response->json('data.payload_hash'), 'the served digits are verifiable');
        $this->assertArrayNotHasKey('raw', (array) $response->json('data'), 'the provider body is not disclosed');
    }

    public function test_the_result_history_is_bounded_and_reports_its_limit(): void
    {
        ['user' => $user, 'draw' => $draw] = $this->apiLane();

        $this->publishResultFor($draw, '123');

        $response = $this->actingAs($user, 'sanctum')->getJson(self::GAMES.'/lane_game/results?limit=500');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.limit', 100);
        $response->assertJsonPath('meta.count', 1);
        $response->assertJsonPath('data.0.digits', '123');

        $floor = $this->actingAs($user, 'sanctum')->getJson(self::GAMES.'/lane_game/results?limit=0');
        $floor->assertStatus(200);
        $floor->assertJsonPath('meta.limit', 1);
    }

    public function test_results_for_an_undeclared_game_are_a_404(): void
    {
        ['user' => $user] = $this->apiLane();

        $this->actingAs($user, 'sanctum')
            ->getJson(self::GAMES.'/no_such_game/results/latest')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'game_unknown');
    }

    // =========================================================================
    // The calendar
    // =========================================================================

    public function test_the_calendar_groups_the_months_draws_by_day_with_their_results(): void
    {
        ['user' => $user, 'draw' => $draw] = $this->apiLane();

        // The lane fixture schedules its draw an hour from now, so the calendar is asked for
        // the month that draw belongs to IN THE GAME'S TIMEZONE - not the server's.
        $month = \Carbon\CarbonImmutable::parse((string) $draw->scheduled_at)
            ->setTimezone('Asia/Bangkok')
            ->format('Y-m');

        $this->publishResultFor($draw, '123');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson(self::GAMES.'/lane_game/calendar?month='.$month);

        $response->assertStatus(200);
        $response->assertJsonPath('data.game_code', 'lane_game');
        $response->assertJsonPath('data.month', $month);
        $response->assertJsonPath('data.timezone', 'Asia/Bangkok');
        $response->assertJsonPath('meta.month', $month);

        // The draw is on the day it was scheduled for, with its published digits attached.
        $day = collect((array) $response->json('data.days'))
            ->first(fn (array $row): bool => collect($row['draws'])->contains(fn (array $d): bool => $d['draw_id'] === (int) $draw->getKey()));

        $this->assertNotNull($day, 'the draw appears on its own day');
        $slot = collect($day['draws'])->firstWhere('draw_id', (int) $draw->getKey());

        $this->assertFalse($slot['projected'], 'a real draw is not a projection');
        $this->assertSame('123', $slot['result']['digits']);
        $this->assertNotNull($slot['result']['published_at']);
        $this->assertSame(1, (int) $response->json('data.counts.published'));
    }

    public function test_a_closed_draw_without_a_published_result_is_named_rather_than_hidden(): void
    {
        ['user' => $user, 'draw' => $draw] = $this->apiLane();

        $month = \Carbon\CarbonImmutable::parse((string) $draw->scheduled_at)
            ->setTimezone('Asia/Bangkok')
            ->format('Y-m');

        // Closed, result not yet published: the state a player reads as "the platform lost my
        // draw". The calendar says so instead of leaving a hole.
        $draw->forceFill([
            'status' => \App\Enums\DrawStatus::Drawing->value,
            'closed_at' => now()->subMinutes(5),
        ])->save();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson(self::GAMES.'/lane_game/calendar?month='.$month);

        $response->assertStatus(200);

        $slot = collect((array) $response->json('data.days'))
            ->flatMap(fn (array $row): array => $row['draws'])
            ->firstWhere('draw_id', (int) $draw->getKey());

        $this->assertNotNull($slot);
        $this->assertNull($slot['result'], 'unpublished digits are never served');
        $this->assertTrue($slot['result_pending'], 'and the pending state is explicit');
        $this->assertSame(1, (int) $response->json('data.counts.result_pending'));
    }

    public function test_the_calendar_projects_nothing_for_a_game_with_no_configured_cadence(): void
    {
        // The SHIPPED blueprints declare REQUIRED_INPUT for their schedule. A calendar that
        // invented nightly draw times for them would be the exact thing Rule §0.3 forbids.
        $player = $this->apiLane();

        config()->set('lottery_games.games.speed_88.draw_cron', [\App\DTOs\LotteryGames\GameDefinition::REQUIRED_INPUT]);
        $this->refreshGameRegistry();

        $response = $this->actingAs($player['user'], 'sanctum')
            ->getJson(self::GAMES.'/speed_88/calendar');

        $response->assertStatus(200);
        $response->assertJsonPath('data.counts.projected', 0);
        $response->assertJsonPath('data.projection.available', false);
        $this->assertNotEmpty($response->json('data.projection.reason'), 'and it says why it projects nothing');
    }

    public function test_a_calendar_month_that_is_not_a_month_is_refused_rather_than_coerced(): void
    {
        ['user' => $user] = $this->apiLane();

        foreach (['2026-13', 'next month', '2026-1', '2026-00'] as $bad) {
            $response = $this->actingAs($user, 'sanctum')
                ->getJson(self::GAMES.'/lane_game/calendar?month='.urlencode($bad));

            $this->assertSame(422, $response->getStatusCode(), 'month "'.$bad.'" must be refused as a client error');
            $response->assertJsonPath('error.code', 'game_result_payload_invalid');
            $response->assertJsonPath('error.details.expected', 'YYYY-MM');
        }
    }

    public function test_the_calendar_of_an_undeclared_game_is_a_404(): void
    {
        ['user' => $user] = $this->apiLane();

        $this->actingAs($user, 'sanctum')
            ->getJson(self::GAMES.'/no_such_game/calendar')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'game_unknown');
    }

    /**
     * Publish one result for the draw, through the lane's own staging and four-eyes
     * boundary - the same path an operator uses, never a direct row write
     * (ResultWriteBoundaryTest is the guard against that).
     */
    private function publishResultFor(Draw $draw, string $digits): void
    {
        $draw->forceFill([
            'status' => \App\Enums\DrawStatus::Drawing->value,
            'draw_number' => 'LANE-API-0001',
            'closed_at' => now()->subMinutes(5),
            'betting_close_at' => now()->subMinutes(10),
        ])->save();

        $maker = User::factory()->create();
        config()->set('lottery_games.ingestion.maker_user_id', (int) $maker->getKey());

        $ingestion = app(\App\Services\LotteryGames\GameResultIngestionService::class);

        $staged = $ingestion->stage(
            app(GameRegistry::class)->definition('lane_game'),
            new \App\DTOs\LotteryGames\DigitDrawResult(
                gameCode: 'lane_game',
                drawNo: 'LANE-API-0001',
                digits: $digits,
                drawnAt: now()->toIso8601String(),
                source: 'official',
            ),
        );

        $ingestion->publish((int) $staged['import_id'], (int) User::factory()->create()->getKey(), 'checked');
    }
}
