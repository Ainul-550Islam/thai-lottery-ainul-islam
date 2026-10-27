<?php

declare(strict_types=1);

namespace Tests\Feature\Player;

use App\Enums\AuditAction;
use App\Enums\ResponsibleGamingLimitStatus;
use App\Enums\ResponsibleGamingLimitType;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\ResponsibleGamingLimit;
use App\Models\ResponsibleGamingLimitVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-16 / P1-01: Proves web responsible gaming limits form persists canonical limits,
 * synchronizes with versioned limit records, writes audit logs, rejects invalid bounds,
 * and renders properly in Profile view.
 */
final class ResponsibleGamingWebTest extends TestCase
{
    use RefreshDatabase;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->player = User::factory()->create([
            'status' => UserStatus::Active,
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->put('/profile/limits', [
            'daily_deposit_limit' => '1000.00',
        ]);

        $response->assertRedirect('/login');
    }

    public function test_authenticated_player_can_view_limits_on_profile_page(): void
    {
        ResponsibleGamingLimit::create([
            'user_id' => $this->player->id,
            'daily_deposit_limit' => '2500.00',
            'single_bet_limit' => '500.00',
            'daily_wagering_limit' => '5000.00',
        ]);

        $response = $this->actingAs($this->player)->get('/profile');

        $response->assertOk();
        $response->assertSee('2500');
        $response->assertSee('500');
        $response->assertSee('5000');
    }

    public function test_authenticated_player_can_persist_and_update_limits(): void
    {
        $response = $this->actingAs($this->player)
            ->from('/profile')
            ->put('/profile/limits', [
                'daily_deposit_limit' => '3000.00',
                'single_bet_limit' => '750.00',
                'daily_wagering_limit' => '6000.00',
            ]);

        $response->assertRedirect('/profile');
        $response->assertSessionHas('success', 'Responsible gaming limits saved.');

        $this->assertDatabaseHas('responsible_gaming_limits', [
            'user_id' => $this->player->id,
            'daily_deposit_limit' => '3000.00',
            'single_bet_limit' => '750.00',
            'daily_wagering_limit' => '6000.00',
        ]);

        // Audit log created
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->player->id,
            'action' => AuditAction::Update->value,
            'auditable_type' => ResponsibleGamingLimit::class,
        ]);

        // Versioned limits pronounced
        $this->assertDatabaseHas('responsible_gaming_limit_versions', [
            'user_id' => $this->player->id,
            'limit_type' => ResponsibleGamingLimitType::DailyDeposit->value,
            'amount' => '3000.00',
            'currency' => 'THB',
        ]);
    }

    public function test_negative_limit_values_are_rejected(): void
    {
        $response = $this->actingAs($this->player)
            ->from('/profile')
            ->put('/profile/limits', [
                'daily_deposit_limit' => '-100.00',
                'single_bet_limit' => '50.00',
            ]);

        $response->assertRedirect('/profile');
        $response->assertSessionHasErrors(['daily_deposit_limit']);
    }

    public function test_blank_limits_clear_or_leave_unrestricted(): void
    {
        $response = $this->actingAs($this->player)
            ->from('/profile')
            ->put('/profile/limits', [
                'daily_deposit_limit' => '',
                'single_bet_limit' => '',
                'daily_wagering_limit' => '',
            ]);

        $response->assertRedirect('/profile');
        $response->assertSessionHas('success', 'Responsible gaming limits saved.');
    }
}
