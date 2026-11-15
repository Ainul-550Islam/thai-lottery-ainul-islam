<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\Enums\GameRulesStatus;
use App\Filament\Resources\LotteryGameResource\Pages\ListLotteryGames;
use App\Models\GameAuditEvent;
use App\Models\LotteryGame;
use App\Models\User;
use App\Policies\LotteryGamePolicy;
use App\Services\LotteryGames\GameAuditRecorder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;
use Tests\Support\LotteryGames\MakesGameConfig;
use Tests\TestCase;

/**
 * Switching selling on and off as its own decision, and every operator decision audited.
 *
 * "Verify rules" has always enabled the game as well (AdminConsoleTest pins that default).
 * These cases cover the other half of the console the spec names: an explicit
 * enable / disable that does not touch the verification, the config switch that lets a
 * deployment separate "verify" from "open to players", and the audit row each decision
 * leaves behind.
 */
final class AdminEnableDisableTest extends TestCase
{
    use DatabaseTruncation;
    use MakesGameConfig;

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

        $this->seed(RolePermissionSeeder::class);
    }

    private function operator(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_enable_switches_selling_on_for_a_verified_game_and_audits_it(): void
    {
        $game = $this->verifiedGame('console_game', [], enabled: false);
        $admin = $this->operator();

        Livewire::actingAs($admin)
            ->test(ListLotteryGames::class)
            ->callTableAction('enable', $game)
            ->assertHasNoTableActionErrors();

        $this->assertTrue((bool) $game->fresh()->enabled);
        $this->assertSame(GameRulesStatus::Verified, $game->fresh()->status, 'enabling does not touch the verification');

        $row = GameAuditEvent::query()->where('event', GameAuditRecorder::GAME_ENABLED)->firstOrFail();
        $this->assertSame('console_game', $row->game_code);
        $this->assertSame((int) $admin->getKey(), $row->actor_id);
    }

    public function test_enable_is_refused_when_the_deployed_rules_drifted(): void
    {
        $game = $this->driftedGame('console_game');
        $game->forceFill(['enabled' => false, 'status' => GameRulesStatus::Verified->value])->save();
        $this->refreshGameRegistry();

        Livewire::actingAs($this->operator())
            ->test(ListLotteryGames::class)
            ->callTableAction('enable', $game->fresh());

        $this->assertFalse((bool) $game->fresh()->enabled, 'a drifted rule set may not be switched on');
        $this->assertSame(0, GameAuditEvent::query()->where('event', GameAuditRecorder::GAME_ENABLED)->count());
        $this->assertSame(
            1,
            GameAuditEvent::query()->where('event', GameAuditRecorder::RULES_VERIFICATION_REFUSED)->count(),
            'the refused attempt is on the trail too',
        );
    }

    public function test_disable_switches_selling_off_without_withdrawing_the_verification(): void
    {
        $game = $this->verifiedGame('console_game', [], enabled: true);

        Livewire::actingAs($this->operator())
            ->test(ListLotteryGames::class)
            ->callTableAction('disable', $game)
            ->assertHasNoTableActionErrors();

        $this->assertFalse((bool) $game->fresh()->enabled);
        $this->assertSame(GameRulesStatus::Verified, $game->fresh()->status);
        $this->assertSame(1, GameAuditEvent::query()->where('event', GameAuditRecorder::GAME_DISABLED)->count());
    }

    public function test_verify_can_be_configured_not_to_enable_the_game(): void
    {
        config()->set('lottery_games.admin.verify_enables_game', false);

        $this->declareGame('console_game');

        Livewire::actingAs($this->operator())
            ->test(ListLotteryGames::class)
            ->callTableAction('verify', LotteryGame::query()->create([
                'code' => 'console_game',
                'name' => 'Fixture Game',
                'status' => GameRulesStatus::Unverified->value,
                'enabled' => false,
            ]))
            ->assertHasNoTableActionErrors();

        $row = LotteryGame::query()->where('code', 'console_game')->firstOrFail();

        $this->assertSame(GameRulesStatus::Verified, $row->status);
        $this->assertFalse((bool) $row->enabled, 'verified, but still switched off until somebody enables it');

        $audit = GameAuditEvent::query()->where('event', GameAuditRecorder::RULES_VERIFIED)->firstOrFail();
        $this->assertFalse($audit->context['enabled_by_verification']);
    }

    public function test_verify_audits_the_checksum_it_stamped(): void
    {
        $definition = $this->declareGame('console_game');

        Livewire::actingAs($this->operator())
            ->test(ListLotteryGames::class)
            ->callTableAction('verify', LotteryGame::query()->create([
                'code' => 'console_game',
                'name' => 'Fixture Game',
                'status' => GameRulesStatus::Unverified->value,
                'enabled' => false,
            ]));

        $audit = GameAuditEvent::query()->where('event', GameAuditRecorder::RULES_VERIFIED)->firstOrFail();

        $this->assertSame($definition->checksum(), $audit->context['rules_checksum']);
    }

    public function test_a_refused_verification_is_audited_and_writes_no_stamp(): void
    {
        $this->declareGame('console_game', ['digits' => 'REQUIRED_INPUT']);

        $row = LotteryGame::query()->create([
            'code' => 'console_game',
            'name' => 'Fixture Game',
            'status' => GameRulesStatus::Unverified->value,
            'enabled' => false,
        ]);

        Livewire::actingAs($this->operator())
            ->test(ListLotteryGames::class)
            ->callTableAction('verify', $row);

        $this->assertSame(GameRulesStatus::Unverified, $row->fresh()->status);
        $this->assertSame(
            1,
            GameAuditEvent::query()->where('event', GameAuditRecorder::RULES_VERIFICATION_REFUSED)->count(),
        );
        $this->assertSame(0, GameAuditEvent::query()->where('event', GameAuditRecorder::RULES_VERIFIED)->count());
    }

    public function test_suspend_and_resume_are_audited(): void
    {
        $game = $this->verifiedGame('console_game');
        $admin = $this->operator();

        Livewire::actingAs($admin)->test(ListLotteryGames::class)->callTableAction('suspend', $game);

        $this->assertSame(1, GameAuditEvent::query()->where('event', GameAuditRecorder::GAME_SUSPENDED)->count());

        $this->refreshGameRegistry();

        Livewire::actingAs($admin)->test(ListLotteryGames::class)->callTableAction('resume', $game->fresh());

        $this->assertSame(1, GameAuditEvent::query()->where('event', GameAuditRecorder::GAME_RESUMED)->count());
    }

    public function test_the_policy_gives_enable_and_disable_the_same_seat_as_verify(): void
    {
        $policy = new LotteryGamePolicy;
        $game = new LotteryGame;
        $admin = $this->operator('admin');
        $player = $this->operator('player');

        $this->assertTrue($policy->enable($admin, $game));
        $this->assertTrue($policy->disable($admin, $game));
        $this->assertFalse($policy->enable($player, $game));
        $this->assertFalse($policy->disable($player, $game));
    }
}
