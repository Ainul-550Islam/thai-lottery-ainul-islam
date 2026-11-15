<?php

declare(strict_types=1);

namespace Tests\Support\LotteryGames;

use App\Enums\BetType;
use App\Enums\GameRulesStatus;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\LimitStatus;
use App\Models\Draw;
use App\Models\LedgerAccount;
use App\Models\LotteryGame;
use App\Models\NumberLimit;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The shared fixtures every digit-game feature test needs: a real player with money, a
 * real open draw, the Batch-1 chart of accounts, and a game that is actually sellable.
 *
 * WHY THIS IS A BASE CLASS AND NOT REPEATED PER SUITE
 * A digit-game bet is not a stub: placing one goes through BetPurchaseService, which
 * requires a wallet with a balance, a numbered draw inside its betting window, a
 * NumberLimit row for the exact (draw, market, number) it touches, and ledger accounts
 * 1000/2000/4000 to exist. Twelve test files needing that setup would be twelve places to
 * fix when Batch 1 changes a requirement — and eleven of them would be silently wrong.
 *
 * WHY DatabaseTruncation AND NOT RefreshDatabase
 * RefreshDatabase opens a transaction around each test, and Batch 1's purchase path
 * refuses to run inside a transaction it did not open. It throws. Truncation commits and
 * cleans up afterwards, so the transaction boundaries exercised here are the production
 * ones. The API suite reached the same conclusion for the same reason.
 *
 * WHY THE DATABASE NAME IS ASSERTED
 * These tests delete rows. DatabaseTruncation would happily truncate whatever
 * DB_DATABASE points at, so the suite refuses to run unless the connection names an
 * explicit test database. There is no code path from here to a real database.
 *
 * WHY THE GAME IS THREE DIGITS
 * Every market this lane settles through is a Batch-1 market with its own width
 * (3d_direct is three digits, 2d_bottom is two). A digit game whose declared width does
 * not equal the width of the market it maps to would be refused by the number-limit
 * layer, so the fixture game is three digits wide with a three-digit exact market and a
 * two-digit suffix market — the shape a real deployment would use. Multipliers are
 * chosen so each market's RTP lands inside the 0.98 ceiling (900/1000 = 0.90 and, at the
 * player's best case of 6 arrangements, 120 x 0.006 = 0.72); a careless multiplier here
 * would turn every "sellable" test into a secret RTP test.
 */
abstract class GameLaneTestCase extends TestCase
{
    use DatabaseTruncation;
    use MakesGameConfig;

    /** @var list<string> */
    protected const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    protected const ACCOUNT_SYSTEM_CASH = '1000';

    protected const ACCOUNT_PLAYER_LIABILITY = '2000';

    protected const ACCOUNT_BET_REVENUE = '4000';

    protected function setUp(): void
    {
        parent::setUp();

        $database = (string) DB::connection()->getDatabaseName();
        $basename = basename($database);

        $this->assertContains(
            $basename,
            self::ALLOWED_DATABASES,
            'Refusing to run destructive tests against database: '.$database,
        );

        $this->seedChartOfAccounts();
    }

    /**
     * The rule block used by the placement and settlement suites: three digits wide,
     * with markets whose widths match the Batch-1 markets they map to.
     *
     * OVERRIDES ARE SHALLOW ON PURPOSE. `['bet_types' => [...]]` REPLACES the market
     * table rather than merging into it, because a three-digit game must not inherit a
     * four-digit market from the shared blueprint - the validator would refuse it, and
     * correctly so.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function laneGameConfig(string $code = 'lane_game', array $overrides = []): array
    {
        $config = $this->gameConfig($code);

        $config['name'] = 'Lane Game';
        $config['digits'] = 3;
        $config['bet_types'] = [
            '3d_direct' => [
                'selection_digits' => 3,
                'match_mode' => 'exact',
                'payout_multiplier' => '900',
                'market_key' => '3d_direct',
            ],
            '3d_any_order' => [
                'selection_digits' => 3,
                'match_mode' => 'any_order',
                'payout_multiplier' => '120',
                'market_key' => '3d_direct',
            ],
            '2d_bottom' => [
                'selection_digits' => 2,
                'match_mode' => 'suffix',
                'payout_multiplier' => '90',
                'market_key' => '2d_bottom',
            ],
        ];
        $config['settlement_market_key'] = '2d_bottom';
        $config['publication_bridge'] = [
            'first_prize' => 'digits_right_padded',
            'bottom_two' => 'last:2',
        ];

        return array_merge($config, $overrides);
    }

    /**
     * Declare the LANE game: three digits, with market widths that match the Batch-1
     * markets it settles through.
     *
     * WHY THIS DOES NOT GO THROUGH declareGame()
     * declareGame() deep-merges its overrides onto the shared four-digit blueprint. That is
     * right for changing one value and wrong for a game of a different SHAPE: the
     * blueprint's four-digit exact market merges back into a three-digit game, and the rules
     * validator then (correctly) refuses it for declaring a market wider than the draw.
     * laneGameConfig() returns a COMPLETE block, so it is registered whole.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function declareLaneGame(string $code = 'lane_game', array $overrides = []): \App\DTOs\LotteryGames\GameDefinition
    {
        return $this->registerGameConfig($code, $this->laneGameConfig($code, $overrides));
    }

    /**
     * A verified, enabled game WITH an open draw.
     *
     * The nine availability gates are satisfied one by one: master switch open, game
     * enabled, rules verified, checksum matching the deployed block, official result
     * source complete, both liability caps configured, an open draw inside its window,
     * the real-money gate open, and legal documents published (the last two are
     * deployment switches, flipped here on purpose so a test that is about something
     * else is not secretly a test of them).
     *
     * @param  array<string, mixed>  $overrides
     * @return array{game: LotteryGame, draw: Draw}
     */
    protected function openLane(string $code = 'lane_game', array $overrides = []): array
    {
        $definition = $this->registerGameConfig($code, $this->laneGameConfig($code, $overrides));

        $row = LotteryGame::query()->create([
            'code' => $code,
            'name' => $definition->name,
            'status' => GameRulesStatus::Verified->value,
            'enabled' => true,
            'rules_checksum' => $definition->checksum(),
            'verified_at' => now(),
        ]);

        $this->enableGameLane(true);
        config()->set('real_money.enabled', true);
        $this->refreshGameRegistry();

        return ['game' => $row->fresh(), 'draw' => $this->openDraw($code)];
    }

    /**
     * A draw of this game that is open AND inside its betting window.
     */
    protected function openDraw(string $code, ?CarbonImmutable $scheduledAt = null): Draw
    {
        $draw = Draw::factory()->create([
            'type' => DrawType::TwoD,
            'game_type' => $code,
            'status' => DrawStatus::Open,
            'scheduled_at' => $scheduledAt ?? now()->addHour(),
            'opened_at' => now()->subHour(),
            'betting_open_at' => now()->subHour(),
            'betting_close_at' => now()->addHour(),
        ]);

        return $draw;
    }

    /**
     * A player with a funded wallet.
     *
     * @return array{user: User, wallet: Wallet}
     */
    protected function player(string $balance = '1000.00'): array
    {
        $user = User::factory()->create();

        $wallet = Wallet::factory()->for($user)->withBalance($balance)->create();

        return ['user' => $user, 'wallet' => $wallet];
    }

    /**
     * Provision the Batch-1 number limit the purchase path demands for one number.
     *
     * The number is padded to the MARKET's width, exactly as the purchase path will
     * pad it, because a limit row stored under a different width is a limit that never
     * applies.
     */
    protected function limitFor(Draw $draw, string $marketKey, string $number): NumberLimit
    {
        $definition = (array) config('lottery.markets.'.$marketKey, []);
        $betType = BetType::tryFrom((string) ($definition['bet_type'] ?? ''));
        $digits = (int) ($definition['digits'] ?? 0);

        $this->assertNotNull($betType, 'market '.$marketKey.' declares no bet type');
        $padded = str_pad($number, max($digits, strlen($number)), '0', STR_PAD_LEFT);

        $limit = NumberLimit::query()
            ->where('draw_id', $draw->getKey())
            ->where('bet_type', $betType->value)
            ->where('number', $padded)
            ->first();

        if ($limit instanceof NumberLimit) {
            return $limit;
        }

        $limit = new NumberLimit();
        $limit->draw_id = (int) $draw->getKey();
        $limit->bet_type = $betType;
        $limit->number = $padded;
        $limit->max_amount = '100000.00';
        $limit->current_amount = '0.00';
        $limit->maximum_payout_exposure = null;
        $limit->current_payout_exposure = '0.00';
        $limit->status = LimitStatus::Active;
        $limit->save();

        return $limit;
    }

    /**
     * A key long enough for the purchase path's own idempotency requirements.
     */
    protected function key(string $seed): string
    {
        return 'lane-'.substr(hash('sha256', $seed), 0, 40);
    }

    protected function seedChartOfAccounts(): void
    {
        $accounts = [
            [self::ACCOUNT_SYSTEM_CASH, 'System Cash', 'asset'],
            [self::ACCOUNT_PLAYER_LIABILITY, 'Player Liability', 'liability'],
            [self::ACCOUNT_BET_REVENUE, 'Bet Revenue', 'revenue'],
        ];

        foreach ($accounts as [$code, $name, $type]) {
            LedgerAccount::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'currency' => Currency::primary()->value,
                    'is_active' => true,
                ],
            );
        }
    }
}
