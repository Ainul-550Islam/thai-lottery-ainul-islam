<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\BetType;
use App\Enums\DrawType;
use App\Enums\UserStatus;
use App\Filament\Pages\LotteryCataloguePage;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\NumberLimit;
use App\Models\User;
use App\Services\Admin\LotteryCatalogueService;
use Database\Seeders\AdminRbacSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The game catalogue: what is sold, at what payout, and what disagrees about it.
 *
 * WHY THIS SUITE EXISTS
 *
 * This page is read-only on purpose — there is no games table, so a CRUD screen would be a row
 * no engine reads. What must be proven instead is that the projection is true and that it fails
 * loudly rather than quietly:
 *
 *   1. The numbers on the screen are `config/lottery.php`'s numbers, not a second opinion.
 *   2. The live facts are the database's facts (draws, bets, stake, limits), counted per game.
 *   3. The drift report is a WATCHDOG now: configuration, BetType and DrawType agree, and the
 *      test proves it by MOVING a configured value and watching DrawType follow it — because a
 *      silent divergence is what used to make a bet refusable with no visible reason anywhere in
 *      the panel.
 *   4. A player cannot open it.
 *
 * The last test deliberately asserts that the page has NO write path: if somebody later adds a
 * "create game" button that writes a table the engine ignores, this suite should break.
 */
final class LotteryCatalogueTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    private const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    protected function setUp(): void
    {
        parent::setUp();

        $database = \DB::connection()->getDatabaseName();

        $this->assertContains(
            basename((string) $database),
            self::ALLOWED_DATABASES,
            'Refusing to run destructive tests against database: '.$database,
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(AdminRbacSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function operator(string $role): User
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $user->assignRole($role);

        $this->actingAs($user->fresh());

        return $user->fresh();
    }

    // ------------------------------------------------------------- access

    public function test_a_lottery_manager_reads_the_catalogue_and_a_player_cannot(): void
    {
        $this->operator('lottery-manager');
        $this->assertTrue(LotteryCataloguePage::canAccess());
        $this->get('/admin/lottery-catalogue')->assertSuccessful();

        $this->operator('player');
        $this->assertFalse(LotteryCataloguePage::canAccess());
        $this->get('/admin/lottery-catalogue')->assertForbidden();
    }

    // ----------------------------------------------------------- the games

    public function test_every_configured_game_type_is_projected_with_its_real_numbers(): void
    {
        $games = app(LotteryCatalogueService::class)->games();

        $byKey = collect($games)->keyBy('key');

        foreach ((array) config('lottery.types') as $key => $definition) {
            $this->assertTrue($byKey->has($key), 'configured type missing from the catalogue: '.$key);

            $row = $byKey->get($key);

            $this->assertSame($definition['label'], $row['label']);
            $this->assertSame((int) $definition['digits'], $row['digits']);
            $this->assertSame((int) $definition['payout_multiplier'], $row['payout_multiplier']);
            $this->assertSame((bool) $definition['enabled'], $row['enabled']);
            $this->assertSame((string) $definition['min_number'], $row['min_number']);
            $this->assertSame((string) $definition['max_number'], $row['max_number']);
            $this->assertSame(array_values($definition['markets']), $row['markets']);
        }

        // And every configured type is a real vocabulary word in both enums.
        foreach ($games as $game) {
            $this->assertTrue($game['bet_type_present'], 'no BetType case for '.$game['key']);
            $this->assertTrue($game['draw_type_present'], 'no DrawType case for '.$game['key']);
        }
    }

    public function test_the_live_facts_are_the_database_facts(): void
    {
        $draw = Draw::factory()->create([
            'type' => DrawType::ThreeD,
            'scheduled_at' => now()->addHours(2),
        ]);

        Bet::factory()->count(2)->create([
            'draw_id' => $draw->getKey(),
            'type' => BetType::ThreeD,
            'stake_amount' => '150.00',
        ]);

        NumberLimit::factory()->create([
            'draw_id' => $draw->getKey(),
            'bet_type' => BetType::ThreeD,
            'number' => '123',
        ]);

        $row = collect(app(LotteryCatalogueService::class)->games())->firstWhere('key', '3d');

        $this->assertNotNull($row);
        $this->assertSame(1, $row['live']['draws_total']);
        $this->assertSame(1, $row['live']['draws_open']);
        $this->assertNotNull($row['live']['next_scheduled_at']);
        $this->assertSame(2, $row['live']['bets_total']);
        $this->assertSame('300.00', $row['live']['stake_total']);
        $this->assertSame(1, $row['live']['number_limits']);

        // A game with no activity reports zero, not somebody else's numbers.
        $twoD = collect(app(LotteryCatalogueService::class)->games())->firstWhere('key', '2d');
        $this->assertSame(0, $twoD['live']['draws_total']);
        $this->assertSame(0, $twoD['live']['bets_total']);
    }

    // ---------------------------------------------------------- the markets

    public function test_every_configured_market_is_projected_including_its_own_multiplier(): void
    {
        $markets = collect(app(LotteryCatalogueService::class)->markets())->keyBy('key');

        foreach ((array) config('lottery.markets') as $key => $definition) {
            $this->assertTrue($markets->has($key), 'configured market missing: '.$key);

            $row = $markets->get($key);

            $this->assertSame($definition['label'], $row['label']);
            $this->assertSame($definition['bet_type'], $row['bet_type']);
            $this->assertSame((string) $definition['source'], $row['source']);
            $this->assertSame((int) $definition['payout_multiplier'], $row['payout_multiplier']);
        }

        // The known per-market override: Run positions pay 3 and 4 while the type row says 12.
        $this->assertTrue($markets->get('run_top')['differs_from_type']);
        $this->assertSame(3, $markets->get('run_top')['payout_multiplier']);
        $this->assertSame(12, $markets->get('run_top')['type_payout_multiplier']);

        // 3D Direct agrees with its type, so nothing is flagged there.
        $this->assertFalse($markets->get('3d_direct')['differs_from_type']);
    }

    // ----------------------------------------------------------- the drift

    public function test_configuration_is_the_single_source_for_the_draw_type_helpers(): void
    {
        // The drift report used to fire here, because DrawType's helpers were hard-coded and
        // disagreed with configuration for Tod (2 digits up to 99 vs 3 up to 999) and Run
        // (2 digits up to 99 vs 1 digit up to 9). The helpers now READ the configuration, so the
        // report is empty — and this test proves it is empty for the right reason rather than by
        // accident, by moving a configured value and watching the helper move with it.
        $drift = collect(app(LotteryCatalogueService::class)->drift());

        $this->assertSame(
            [],
            $drift->values()->all(),
            'Configuration, BetType and DrawType must describe the same games: '.$drift->toJson(),
        );

        foreach (DrawType::cases() as $type) {
            $key = $type->value;

            $this->assertSame((int) config('lottery.types.'.$key.'.digits'), $type->digits(), $key.' digits');
            $this->assertSame((int) config('lottery.types.'.$key.'.max_number'), $type->maxNumber(), $key.' max');
            $this->assertSame((int) config('lottery.types.'.$key.'.min_number'), $type->minNumber(), $key.' min');
            $this->assertSame((int) config('lottery.types.'.$key.'.payout_multiplier'), $type->payoutMultiplier(), $key.' multiplier');
        }

        // The proof that the helpers delegate instead of coincidentally matching: change the
        // configuration and the enum follows it, with no code change and no cache to clear.
        config(['lottery.types.tod.digits' => 4, 'lottery.types.tod.max_number' => '9999']);

        $this->assertSame(4, DrawType::Tod->digits(), 'the helper must read configuration, not a literal');
        $this->assertSame(9999, DrawType::Tod->maxNumber());

        // Restore, and note what the watchdog would have said if the helpers had been literals
        // again: with configuration at 4 and DrawType still at 3, `drift()` would report a row.
        // It reports none here, which is the whole point — the two move together.
        config(['lottery.types.tod.digits' => 3, 'lottery.types.tod.max_number' => '999']);

        $this->assertTrue(
            collect(app(LotteryCatalogueService::class)->drift())->isEmpty(),
            'the catalogue re-checks the same numbers the enum now reads',
        );
    }

    public function test_the_products_report_shows_switched_off_products_too(): void
    {
        $products = collect(app(LotteryCatalogueService::class)->products())->keyBy('key');

        $this->assertCount(4, $products);

        foreach (['national_lottery', 'weekly_lottery', 'bingo_lottery', 'pcso_lottery'] as $key) {
            $this->assertSame((bool) config($key.'.enabled', false), $products->get($key)['enabled']);
        }
    }

    // ----------------------------------------------------------- the page

    public function test_the_page_renders_the_catalogue_and_offers_no_write_path(): void
    {
        $this->operator('lottery-manager');

        Livewire::test(LotteryCataloguePage::class)
            ->assertOk()
            ->assertSee('Game types')
            ->assertSee('Configuration drift')
            ->assertSee('3D');

        // No form, no action, no create button: the catalogue is configuration-as-code.
        $reflection = new \ReflectionClass(LotteryCataloguePage::class);
        $source = (string) file_get_contents((string) $reflection->getFileName());

        foreach (['Actions\\Action', 'CreateAction', '->form(', 'handleRecordCreation'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                'the catalogue page must stay read-only, found: '.$forbidden,
            );
        }
    }
}
