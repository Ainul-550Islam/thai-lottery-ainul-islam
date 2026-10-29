<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WalletStatus;
use App\Models\Agent;
use App\Models\AgentHierarchy;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Draw;
use App\Models\LotteryEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Commission\MultiTierCommissionDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTierCommissionCalculationTest extends TestCase
{
    use RefreshDatabase;

    private MultiTierCommissionDistributionService $commissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->commissionService = app(MultiTierCommissionDistributionService::class);
    }

    public function test_multi_tier_commission_distributes_upward_accurately(): void
    {
        // 1. Create Agents (L1 Master, L2 Direct) + Player with real User FKs
        $masterUser = User::factory()->create();
        $directUser = User::factory()->create();
        $playerUser = User::factory()->create();

        $masterAgent = Agent::create([
            'user_id' => $masterUser->id,
            'agent_code' => 'AGT-MASTER-01',
            'status' => \App\Enums\AgentStatus::Active,
        ]);
        $masterWallet = new Wallet(['user_id' => $masterUser->id, 'type' => 'commission', 'currency' => 'THB']);
        $masterWallet->status = WalletStatus::Active;
        $masterWallet->save();

        $directAgent = Agent::create([
            'user_id' => $directUser->id,
            'agent_code' => 'AGT-DIRECT-01',
            'parent_agent_id' => $masterAgent->id,
            'status' => \App\Enums\AgentStatus::Active,
        ]);
        $directWallet = new Wallet(['user_id' => $directUser->id, 'type' => 'commission', 'currency' => 'THB']);
        $directWallet->status = WalletStatus::Active;
        $directWallet->save();

        $masterAgent->forceFill(['tier_level' => 1, 'direct_commission_rate' => '10.00'])->save();
        $directAgent->forceFill(['tier_level' => 2, 'direct_commission_rate' => '5.00'])->save();

        // 2. Closure Table setup for hierarchy
        AgentHierarchy::create(['ancestor_id' => $directAgent->id, 'descendant_id' => $directAgent->id, 'depth' => 0]);
        AgentHierarchy::create(['ancestor_id' => $masterAgent->id, 'descendant_id' => $directAgent->id, 'depth' => 1]);

        // 3. Rules
        CommissionRule::create(['game_type' => 'ALL', 'tier_level' => 1, 'override_percentage' => '2.00', 'is_active' => true]);

        // 4. Create Draw and Ticket Entry (1000 THB stake)
        $draw = Draw::create([
            'draw_code' => 'COMM-DRAW-01',
            'game_type' => 'THAI_6D',
            'draw_date' => now(),
            'betting_starts_at' => now()->subDay(),
            'betting_ends_at' => now()->addDay(),
            'status' => 'OPEN',
        ]);

        $entry = LotteryEntry::create([
            'draw_id' => $draw->id,
            'user_id' => $playerUser->id,
            'agent_id' => $directAgent->id,
            'voucher_number' => 'VCH-COMM-001',
            'ticket_number' => '123456',
            'bet_type' => 'DIRECT_6D',
            'stake_amount' => '1000.0000',
            'status' => 'PENDING',
            'idempotency_key' => 'IDEM-COMM-TEST-001',
        ]);

        // Execute Distribution
        $records = $this->commissionService->distributeForEntry($entry->id);

        $this->assertCount(2, $records);

        // Direct Agent gets 5% of 1000 = 50 THB
        $directLedger = CommissionLedger::where('agent_id', $directAgent->id)->first();
        $this->assertEquals('50.0000', $directLedger->commission_amount);

        // Master Agent gets 2% override of 1000 = 20 THB
        $masterLedger = CommissionLedger::where('agent_id', $masterAgent->id)->first();
        $this->assertEquals('20.0000', $masterLedger->commission_amount);

        // Wallets credited
        $this->assertEquals('50.0000', bcadd((string) $directWallet->fresh()->balance, '0', 4));
        $this->assertEquals('20.0000', bcadd((string) $masterWallet->fresh()->balance, '0', 4));
    }
}
