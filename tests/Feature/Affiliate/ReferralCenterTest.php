<?php

declare(strict_types=1);

namespace Tests\Feature\Affiliate;

use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Bet;
use App\Models\User;
use App\Services\Agent\AgentReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ReferralCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_referral_url_qr_payload_30d_series_masked_team_and_non_hardcoded_rate(): void
    {
        $owner = User::factory()->create(['username' => 'captainthai']);
        $agent = Agent::factory()->active()->create([
            'user_id' => $owner->getKey(),
            'agent_code' => 'THAI777',
            'commission_rate' => '6.5000',
        ]);

        $referred = User::factory()->create(['username' => 'playeralpha99']);
        app(AgentReferralService::class)->attributeUser($referred, 'THAI777');

        $bet = Bet::factory()->lost()->create([
            'user_id' => $referred->getKey(),
            'stake_amount' => '1000.00',
            'currency' => Currency::THB,
        ]);

        AgentCommission::query()->create([
            'reference_number' => 'COM-TEST-0001',
            'agent_id' => $agent->getKey(),
            'bet_id' => $bet->getKey(),
            'draw_id' => $bet->draw_id,
            'currency' => Currency::THB,
            'base_amount' => '1000.00',
            'commission_rate' => '6.5000',
            'commission_amount' => '65.00',
            'status' => CommissionStatus::Paid,
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($owner);

        $center = $this->getJson('/api/v1/me/referral-center')
            ->assertOk()
            ->assertJsonPath('data.referral_code', 'THAI777')
            ->assertJsonPath('data.commission_rate_percent', '6.50')
            ->assertJsonPath('data.stats.total_referrals', 1)
            ->assertJsonPath('data.stats.active_referrals', 1)
            ->assertJsonPath('data.stats.lifetime_commission', '65.00')
            ->assertJsonPath('data.team.pagination.total', 1)
            ->assertJsonPath('data.team.items.0.is_active', true)
            ->assertJsonPath('data.team.items.0.commission_generated', '65.00');

        $this->assertSame(
            $center->json('data.referral_url'),
            $center->json('data.qr_payload'),
        );
        $this->assertCount(30, $center->json('data.earnings_30d'));
        $this->assertNotSame('35.00', $center->json('data.commission_rate_percent'));

        $masked = (string) $center->json('data.team.items.0.masked_username');
        $this->assertStringContainsString('***', $masked);
        $this->assertStringNotContainsString('playeralpha99', (string) $center->getContent());
    }
}
