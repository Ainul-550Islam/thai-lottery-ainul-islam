<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Enums\AgentStatus;
use App\Enums\Currency;
use App\Enums\SelfExclusionStatus;
use App\Enums\UserStatus;
use App\Exceptions\SelfExclusionException;
use App\Models\Agent;
use App\Models\SelfExclusion;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ResponsibleGaming\ResponsibleGamingEnforcementService;
use App\Services\ResponsibleGaming\SelfExclusionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P0-E / P0-F: User::isSelfExcluded is enforced on the purchase path;
 * Agent::canAcceptPlayers is server-computed.
 */
final class SelfExclusionAndAgentGateTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(): User
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        Wallet::factory()->create([
            'user_id' => $user->getKey(),
            'currency' => Currency::THB,
            'balance' => '10000.00',
        ]);

        return $user;
    }

    #[Test]
    public function is_self_excluded_false_without_exclusion(): void
    {
        $user = $this->activeUser();
        $this->assertFalse($user->isSelfExcluded());
    }

    #[Test]
    public function is_self_excluded_true_for_future_window(): void
    {
        $user = $this->activeUser();
        $rg = $user->responsibleGamingLimit()->firstOrCreate(
            ['user_id' => $user->getKey()],
            ['self_excluded_until' => null],
        );
        $rg->forceFill(['self_excluded_until' => now()->addDays(30)])->save();

        $this->assertTrue($user->fresh()->isSelfExcluded());
        $this->assertFalse($user->fresh()->canTransact());
    }

    #[Test]
    public function is_self_excluded_false_for_expired_window(): void
    {
        $user = $this->activeUser();
        $rg = $user->responsibleGamingLimit()->firstOrCreate(
            ['user_id' => $user->getKey()],
            ['self_excluded_until' => null],
        );
        $rg->forceFill(['self_excluded_until' => now()->subDay()])->save();

        $this->assertFalse($user->fresh()->isSelfExcluded());
    }

    #[Test]
    public function enforcement_service_blocks_bets_for_active_self_exclusion(): void
    {
        $user = $this->activeUser();
        $this->makeActiveExclusion($user, now()->addDays(7));

        $this->expectException(SelfExclusionException::class);
        app(ResponsibleGamingEnforcementService::class)
            ->assertBetAllowed($user->fresh(), '80.00');
    }

    #[Test]
    public function expired_self_exclusion_does_not_block_bets(): void
    {
        $user = $this->activeUser();
        $this->makeActiveExclusion($user, now()->subDay());

        app(ResponsibleGamingEnforcementService::class)
            ->assertBetAllowed($user->fresh(), '80.00');
        $this->assertNull(
            app(SelfExclusionService::class)->currentActiveFor((int) $user->getKey()),
            'expired exclusion must not gate',
        );
    }

    #[Test]
    public function concurrent_overlapping_exclusions_still_block(): void
    {
        $user = $this->activeUser();
        $this->makeActiveExclusion($user, now()->addDays(10));
        $this->makeActiveExclusion($user, now()->addDays(20));

        $this->expectException(SelfExclusionException::class);
        app(ResponsibleGamingEnforcementService::class)
            ->assertBetAllowed($user->fresh(), '80.00');
    }

    #[Test]
    public function enforcement_service_allows_bet_for_clean_user(): void
    {
        $user = $this->activeUser();
        app(ResponsibleGamingEnforcementService::class)
            ->assertBetAllowed($user, '80.00');
        $this->assertTrue(true, 'clean active user must pass assertBetAllowed');
    }

    #[Test]
    public function agent_can_accept_players_is_server_computed(): void
    {
        $active = $this->makeAgent(AgentStatus::Active, UserStatus::Active);
        $this->assertTrue($active->canAcceptPlayers());

        $suspended = $this->makeAgent(AgentStatus::Suspended, UserStatus::Active);
        $this->assertFalse($suspended->canAcceptPlayers());

        $inactive = $this->makeAgent(AgentStatus::Inactive, UserStatus::Active);
        $this->assertFalse($inactive->canAcceptPlayers());

        // Suspended USER owning an agent also refuses.
        $ownedBySuspendedUser = $this->makeAgent(AgentStatus::Active, UserStatus::Suspended);
        $this->assertFalse($ownedBySuspendedUser->canAcceptPlayers());
    }

    private function makeActiveExclusion(User $user, \DateTimeInterface $endsAt): SelfExclusion
    {
        return SelfExclusion::query()->create([
            'user_id' => $user->getKey(),
            'status' => SelfExclusionStatus::Active,
            'scope' => 'all',
            'reason_code' => 'player_request',
            'request_fingerprint' => bin2hex(random_bytes(8)),
            'effective_at' => now()->subHour(),
            'ends_at' => $endsAt,
            'activated_at' => now()->subHour(),
        ]);
    }

    private function makeAgent(AgentStatus $agentStatus, UserStatus $userStatus): Agent
    {
        $user = User::factory()->create(['status' => $userStatus]);
        Wallet::factory()->create([
            'user_id' => $user->getKey(),
            'currency' => Currency::THB,
            'balance' => '0.00',
        ]);

        return Agent::query()->create([
            'agent_code' => 'AG-'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => $user->getKey(),
            'parent_agent_id' => null,
            'status' => $agentStatus,
            'currency' => Currency::THB,
            'commission_rate' => '0.0500',
            'total_referrals' => 0,
            'total_commission_earned' => '0.00',
            'total_commission_paid' => '0.00',
            'approved_at' => $agentStatus === AgentStatus::Active ? now() : null,
            'metadata' => [],
        ]);
    }
}
