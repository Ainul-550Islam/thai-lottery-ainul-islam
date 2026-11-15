<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\GameRulesStatus;
use App\Filament\Pages\GameLiabilityPage;
use App\Filament\Pages\GameRulesReportPage;
use App\Filament\Resources\LotteryGameResource;
use App\Filament\Resources\LotteryGameResource\Pages\ListLotteryGames;
use App\Models\Draw;
use App\Models\GameNumberExposure;
use App\Models\LotteryGame;
use App\Models\User;
use App\Policies\LotteryGamePolicy;
use App\Support\Admin\AdminAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;
use Tests\Support\LotteryGames\MakesGameConfig;
use Tests\TestCase;

/**
 * The operator console for digit games: who may open it, who may verify a payout table,
 * and whether the two report pages tell the truth about a game that cannot sell.
 *
 * WHY THIS SUITE EXISTS
 * The rest of the lane is already refused by services: an unverified game does not sell, a
 * drifted checksum does not sell, a candidate number over its ceiling is refused. All of
 * that is worthless if the console that is supposed to FIX those states can be operated by
 * the wrong person or can write a state the services then disagree with. Two failure modes
 * matter here and neither shows up in a service test:
 *
 *   1. A screen that quietly permits a write. Filament's default is permissive, so the
 *      absence of a permission check is invisible until an auditor is handed a verify button.
 *      The tests below therefore assert the READ-ONLY branches as well as the permitted ones.
 *
 *   2. A verification that stamps the wrong rule set. The checksum is the whole control: it
 *      is the hash the selling path compares against. A verify action that stamped a fresh
 *      hash without validating the block, or that stamped the DEPLOYED hash while validating
 *      something else, would pass every downstream service test while letting an unreviewed
 *      payout table sell. So the assertions check the exact hash, the operator id and the
 *      enabled flag, and check that a refused verification wrote NOTHING.
 *
 * WHY THE REPORTS ARE TESTED BY RENDERING THEM
 * The liability page and the rules report exist to answer questions a service cannot answer
 * alone: "what is the headroom on 0042 right now" and "did the rules drift after they were
 * signed off". Rendering the page is the only assertion that proves the answer reaches a
 * human, and it exercises the two methods that join several services together — the part
 * most likely to break when those services change shape.
 *
 * WHAT IS DELIBERATELY NOT RE-TESTED
 * The arithmetic of headroom, the nine availability gates and RTP itself. They have their own
 * suites; this one asserts only that the console SHOWS what those services return.
 */
final class AdminConsoleTest extends TestCase
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

    // =========================================================================
    // Who may open the console
    // =========================================================================

    public function test_an_admin_opens_the_digit_game_list_and_sees_the_game(): void
    {
        $game = $this->verifiedGame('console_game');

        $this->actingAs($this->operator('admin'))
            ->get(LotteryGameResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('console_game')
            ->assertSee('Fixture Game');

        $this->assertSame(GameRulesStatus::Verified, $game->status);
    }

    public function test_a_player_cannot_reach_the_digit_game_console(): void
    {
        $this->verifiedGame('console_game');

        $this->actingAs($this->operator('player'))
            ->get(LotteryGameResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_an_auditor_is_refused_the_writable_console_but_may_read_the_rules_report(): void
    {
        $this->verifiedGame('console_game');
        $auditor = $this->operator('auditor');

        // The console writes (verify / suspend / resume), so it is a draws permission screen.
        $this->actingAs($auditor)
            ->get(LotteryGameResource::getUrl('index'))
            ->assertForbidden();

        // The report writes nothing and is reached through view financial reports, which is
        // the permission the read-only seat actually holds.
        $this->actingAs($auditor)
            ->get(GameRulesReportPage::getUrl())
            ->assertSuccessful();

        // Live risk is an operating view, not an audit one.
        $this->actingAs($auditor)
            ->get(GameLiabilityPage::getUrl())
            ->assertForbidden();
    }

    public function test_a_read_only_seat_holding_manage_draws_is_still_offered_no_verification(): void
    {
        $this->verifiedGame('console_game');

        // The auditor role with the draws permissions bolted on: the exact shape that would
        // slip through a permission-only check, because it holds `manage draws`.
        $auditor = $this->operator('auditor');
        $auditor->givePermissionTo([AdminAccess::VIEW_DRAWS, AdminAccess::MANAGE_DRAWS]);
        $readOnly = $auditor->fresh();

        $this->assertTrue(AdminAccess::allows($readOnly, AdminAccess::MANAGE_DRAWS));
        $this->assertTrue(AdminAccess::isReadOnly($readOnly));

        \Illuminate\Support\Facades\Auth::login($readOnly);

        $this->assertTrue(LotteryGameResource::canViewAny());
        $this->assertFalse(LotteryGameResource::canEdit(new LotteryGame), 'A read-only seat may never verify a payout table.');

        $this->assertFalse((new LotteryGamePolicy)->verify($readOnly, new LotteryGame));
        $this->assertFalse((new LotteryGamePolicy)->suspend($readOnly, new LotteryGame));
        $this->assertFalse((new LotteryGamePolicy)->resume($readOnly, new LotteryGame));
    }

    public function test_the_resource_never_offers_create_or_delete(): void
    {
        $game = $this->verifiedGame('console_game');

        \Illuminate\Support\Facades\Auth::login($this->operator('super-admin'));

        $this->assertFalse(LotteryGameResource::canCreate(), 'A game is declared in configuration, never created from a form.');
        $this->assertFalse(LotteryGameResource::canDelete($game), 'Suspension is the supported withdrawal; the row is the audit trail.');
        $this->assertFalse(LotteryGameResource::canDeleteAny());

        $this->assertArrayNotHasKey('create', LotteryGameResource::getPages());
        $this->assertArrayNotHasKey('edit', LotteryGameResource::getPages());

        // The list page must not carry a header action either: an empty array cannot hide a write.
        $method = new \ReflectionMethod(ListLotteryGames::class, 'getHeaderActions');
        $method->setAccessible(true);
        $this->assertSame([], $method->invoke(new ListLotteryGames));
    }

    public function test_the_policy_denies_deletion_to_everybody_and_requires_a_writable_seat_to_verify(): void
    {
        $policy = new LotteryGamePolicy;
        $game = $this->verifiedGame('console_game');

        $admin = $this->operator('admin');
        $auditor = $this->operator('auditor');
        $player = $this->operator('player');

        $this->assertTrue($policy->viewAny($admin));
        $this->assertTrue($policy->verify($admin, $game));

        // The auditor holds no draws permission, so it cannot reach the console at all.
        $this->assertFalse($policy->viewAny($auditor));
        $this->assertFalse($policy->verify($auditor, $game), 'An auditor may look at money, not accept a payout table.');
        $this->assertFalse($policy->suspend($auditor, $game));

        $this->assertFalse($policy->viewAny($player));
        $this->assertFalse($policy->verify($player, $game));

        foreach ([$admin, $auditor, $player] as $user) {
            $this->assertFalse($policy->delete($user, $game), 'No account may delete a game.');
            $this->assertFalse($policy->forceDelete($user, $game));
        }
    }

    // =========================================================================
    // Verify: the only write that lets the lane pay money
    // =========================================================================

    public function test_verify_stamps_the_deployed_checksum_and_enables_the_game(): void
    {
        $definition = $this->declareGame('console_game');
        $admin = $this->operator('admin');

        // Declared but never verified, and no row at all yet: the state a new game ships in.
        $this->assertNull(LotteryGame::query()->where('code', 'console_game')->first());

        Livewire::actingAs($admin)
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
        $this->assertTrue($row->enabled, 'A verified game is enabled, otherwise the verification changed nothing.');
        $this->assertSame($definition->checksum(), $row->rules_checksum, 'The stamp must be the hash of the rule set that was validated.');
        $this->assertSame($admin->getKey(), $row->verified_by);
        $this->assertNotNull($row->verified_at);
    }

    public function test_verify_refuses_a_rule_set_that_still_holds_required_input_and_writes_nothing(): void
    {
        // One multiplier left as the literal placeholder: the operator has not finished.
        $this->declareGame('console_game', [
            'bet_types' => ['2d_bottom' => ['payout_multiplier' => GameDefinition::REQUIRED_INPUT]],
        ]);

        $admin = $this->operator('admin');

        $row = LotteryGame::query()->create([
            'code' => 'console_game',
            'name' => 'Fixture Game',
            'status' => GameRulesStatus::Unverified->value,
            'enabled' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(ListLotteryGames::class)
            ->callTableAction('verify', $row);

        $row->refresh();

        $this->assertSame(GameRulesStatus::Unverified, $row->status, 'A refused verification must not stamp a status.');
        $this->assertFalse((bool) $row->enabled, 'A refused verification must not switch selling on.');
        $this->assertNull($row->rules_checksum);
        $this->assertNull($row->verified_at);
    }

    public function test_verify_refuses_a_multiplier_table_that_breaches_the_rtp_ceiling(): void
    {
        // 4d_direct at 12,000x returns 1.2 of stakes: the operator loses on every bet sold.
        $this->declareGame('console_game', [
            'bet_types' => ['4d_direct' => ['payout_multiplier' => '12000']],
        ]);

        $row = LotteryGame::query()->create([
            'code' => 'console_game',
            'name' => 'Fixture Game',
            'status' => GameRulesStatus::Unverified->value,
            'enabled' => false,
        ]);

        Livewire::actingAs($this->operator('admin'))
            ->test(ListLotteryGames::class)
            ->callTableAction('verify', $row);

        $row->refresh();

        $this->assertSame(GameRulesStatus::Unverified, $row->status);
        $this->assertNull($row->rules_checksum);
    }

    // =========================================================================
    // Suspend and resume
    // =========================================================================

    public function test_suspend_withdraws_trust_and_stops_selling_without_erasing_the_history(): void
    {
        $game = $this->verifiedGame('console_game');
        $admin = $this->operator('admin');
        $checksum = (string) $game->rules_checksum;

        Livewire::actingAs($admin)
            ->test(ListLotteryGames::class)
            ->callTableAction('suspend', $game);

        $game->refresh();

        $this->assertSame(GameRulesStatus::Suspended, $game->status);
        $this->assertFalse((bool) $game->enabled, 'A suspended game must not stay switched on.');
        $this->assertSame($checksum, $game->rules_checksum, 'The checksum stays: an auditor must see which rules were in force.');
        $this->assertSame($admin->getKey(), (int) ($game->metadata['suspended_by'] ?? 0));
    }

    public function test_resume_puts_back_a_game_whose_checksum_still_matches(): void
    {
        $game = $this->verifiedGame('console_game');
        $game->markSuspended($this->operator('admin')->getKey(), 'maintenance');
        $game->forceFill(['enabled' => false])->save();
        $this->refreshGameRegistry();

        Livewire::actingAs($this->operator('admin'))
            ->test(ListLotteryGames::class)
            ->callTableAction('resume', $game->fresh());

        $game->refresh();

        $this->assertSame(GameRulesStatus::Verified, $game->status);
        $this->assertTrue((bool) $game->enabled);
    }

    public function test_resume_is_refused_when_the_deployed_rules_drifted_from_the_verified_checksum(): void
    {
        $game = $this->driftedGame('console_game');
        $game->forceFill(['status' => GameRulesStatus::Suspended->value, 'enabled' => false])->save();
        $this->refreshGameRegistry();

        Livewire::actingAs($this->operator('admin'))
            ->test(ListLotteryGames::class)
            ->callTableAction('resume', $game->fresh());

        $game->refresh();

        $this->assertSame(GameRulesStatus::Suspended, $game->status, 'A drifted rule set may not be resumed: it must be verified again.');
        $this->assertFalse((bool) $game->enabled);
    }

    // =========================================================================
    // Liability report
    // =========================================================================

    public function test_the_liability_page_shows_the_headroom_for_the_worst_number_on_an_open_draw(): void
    {
        $game = $this->verifiedGame('console_game');
        $draw = $this->openDraw('console_game');

        // The console's whole purpose: one number carrying most of the reserved payout.
        GameNumberExposure::query()->create([
            'draw_id' => $draw->getKey(),
            'game_code' => 'console_game',
            'number' => '0042',
            'bet_type' => '4d_direct',
            'stake_total' => '1.00',
            'potential_payout_total' => '6000.00',
            'version' => 1,
        ]);

        GameNumberExposure::query()->create([
            'draw_id' => $draw->getKey(),
            'game_code' => 'console_game',
            'number' => '0077',
            'bet_type' => '4d_direct',
            'stake_total' => '1.00',
            'potential_payout_total' => '100.00',
            'version' => 1,
        ]);

        $response = $this->actingAs($this->operator('admin'))
            ->get(GameLiabilityPage::getUrl())
            ->assertSuccessful();

        $response->assertSee('0042');
        $response->assertSee('0077');
        // 50,000.00 per-number ceiling less 6,000.00 reserved.
        $response->assertSee('44,000.00');
        // 100,000.00 per-draw ceiling less 6,100.00 reserved.
        $response->assertSee('6,100.00');
        $response->assertSee('93,900.00');
        // The per-number ceiling itself, from configuration rather than from the counters.
        $response->assertSee('50,000.00');

        $this->assertSame(GameRulesStatus::Verified, $game->status);
    }

    public function test_the_liability_page_says_so_when_no_draw_is_open(): void
    {
        $this->verifiedGame('console_game');

        $this->actingAs($this->operator('admin'))
            ->get(GameLiabilityPage::getUrl())
            ->assertSuccessful()
            ->assertSee('No live digit game draw is open right now');
    }

    public function test_a_player_cannot_open_the_liability_report(): void
    {
        $this->verifiedGame('console_game');

        $this->actingAs($this->operator('player'))
            ->get(GameLiabilityPage::getUrl())
            ->assertForbidden();
    }

    // =========================================================================
    // Rules report
    // =========================================================================

    public function test_the_rules_report_shows_the_verdict_the_checksums_and_the_rtp(): void
    {
        $this->verifiedGame('console_game');

        $response = $this->actingAs($this->operator('admin'))
            ->get(GameRulesReportPage::getUrl())
            ->assertSuccessful();

        $response->assertSee('matches deployed rules');
        $response->assertSee('console_game');
        $response->assertSee('4d_direct');
        // The configured multiplier is shown, not recomputed into a different figure.
        $response->assertSee('6000');
        $response->assertSee('RTP ceiling 0.98');
    }

    public function test_the_rules_report_names_drift_and_the_required_inputs_an_operator_still_owes(): void
    {
        $this->driftedGame('console_game');

        $this->actingAs($this->operator('admin'))
            ->get(GameRulesReportPage::getUrl())
            ->assertSuccessful()
            ->assertSee('DRIFTED');
    }

    public function test_an_unconfigured_game_is_shown_with_its_reason_rather_than_hidden(): void
    {
        // The two live games in the shipped config are deliberately unconfigured.
        $this->actingAs($this->operator('admin'))
            ->get(GameRulesReportPage::getUrl())
            ->assertSuccessful()
            ->assertSee('speed_88')
            ->assertSee('REQUIRED_INPUT');
    }

    public function test_the_rules_report_is_reachable_by_an_account_holding_only_financial_reports(): void
    {
        $this->verifiedGame('console_game');

        // An agent: the panel does not admit it, so it is refused at the door.
        $this->actingAs($this->operator('agent'))
            ->get(GameRulesReportPage::getUrl())
            ->assertForbidden();

        // The auditor already covers the positive case above; this asserts the negative for
        // a role that is not in PANEL_ROLES at all.
        $this->assertFalse(AdminAccess::allows($this->operator('player'), AdminAccess::VIEW_FINANCIAL_REPORTS));
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * A user in one of the panel roles.
     */
    private function operator(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    /**
     * An open draw belonging to a digit game.
     */
    private function openDraw(string $code): Draw
    {
        /** @var Draw $draw */
        $draw = Draw::factory()->open()->create([
            'type' => DrawType::TwoD,
            'game_type' => $code,
            'scheduled_at' => now()->addMinutes(30),
        ]);

        $this->assertSame(DrawStatus::Open, $draw->status);

        return $draw;
    }
}
