<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\AgentHierarchy;
use App\Models\CommissionRule;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AgentHierarchySeeder extends Seeder
{
    /**
     * Seed 4-Tier Agent Network with Closure Table hierarchy and commission rules.
     */
    public function run(): void
    {
        // 1. Seed Global Commission Rules for Thai Lottery
        CommissionRule::firstOrCreate(
            ['game_type' => 'ALL', 'tier_level' => 1],
            ['id' => Str::uuid()->toString(), 'override_percentage' => '1.50', 'calculation_basis' => 'TURNOVER', 'is_active' => true]
        );
        CommissionRule::firstOrCreate(
            ['game_type' => 'ALL', 'tier_level' => 2],
            ['id' => Str::uuid()->toString(), 'override_percentage' => '1.00', 'calculation_basis' => 'TURNOVER', 'is_active' => true]
        );
        CommissionRule::firstOrCreate(
            ['game_type' => 'ALL', 'tier_level' => 3],
            ['id' => Str::uuid()->toString(), 'override_percentage' => '0.50', 'calculation_basis' => 'TURNOVER', 'is_active' => true]
        );

        // 2. Create Tier 1: Master Agent
        $masterUserId = Str::uuid()->toString();
        $masterAgent = Agent::firstOrCreate(
            ['agent_code' => 'AGT-MASTER-HQ'],
            [
                'id' => Str::uuid()->toString(),
                'user_id' => $masterUserId,
                'parent_agent_id' => null,
                'tier_level' => 1,
                'lineage_path' => '/',
                'status' => 'ACTIVE',
                'direct_commission_rate' => '10.00',
            ]
        );
        Wallet::firstOrCreate(['user_id' => $masterUserId, 'type' => 'commission'], ['id' => Str::uuid()->toString(), 'currency' => 'THB', 'balance' => '0.0000', 'locked_balance' => '0.0000', 'status' => 'active']);

        // Self-reference closure
        AgentHierarchy::firstOrCreate(['ancestor_id' => $masterAgent->id, 'descendant_id' => $masterAgent->id], ['id' => Str::uuid()->toString(), 'depth' => 0]);

        // 3. Create Tier 2: Super Agent
        $superUserId = Str::uuid()->toString();
        $superAgent = Agent::firstOrCreate(
            ['agent_code' => 'AGT-SUPER-BANGKOK'],
            [
                'id' => Str::uuid()->toString(),
                'user_id' => $superUserId,
                'parent_agent_id' => $masterAgent->id,
                'tier_level' => 2,
                'lineage_path' => "/{$masterAgent->id}/",
                'status' => 'ACTIVE',
                'direct_commission_rate' => '8.00',
            ]
        );
        Wallet::firstOrCreate(['user_id' => $superUserId, 'type' => 'commission'], ['id' => Str::uuid()->toString(), 'currency' => 'THB', 'balance' => '0.0000', 'locked_balance' => '0.0000', 'status' => 'active']);

        AgentHierarchy::firstOrCreate(['ancestor_id' => $superAgent->id, 'descendant_id' => $superAgent->id], ['id' => Str::uuid()->toString(), 'depth' => 0]);
        AgentHierarchy::firstOrCreate(['ancestor_id' => $masterAgent->id, 'descendant_id' => $superAgent->id], ['id' => Str::uuid()->toString(), 'depth' => 1]);

        // 4. Create Tier 3: Direct Retail Agent
        $directUserId = Str::uuid()->toString();
        $directAgent = Agent::firstOrCreate(
            ['agent_code' => 'AGT-DIRECT-SHOP-01'],
            [
                'id' => Str::uuid()->toString(),
                'user_id' => $directUserId,
                'parent_agent_id' => $superAgent->id,
                'tier_level' => 3,
                'lineage_path' => "/{$masterAgent->id}/{$superAgent->id}/",
                'status' => 'ACTIVE',
                'direct_commission_rate' => '5.00',
            ]
        );
        Wallet::firstOrCreate(['user_id' => $directUserId, 'type' => 'commission'], ['id' => Str::uuid()->toString(), 'currency' => 'THB', 'balance' => '0.0000', 'locked_balance' => '0.0000', 'status' => 'active']);

        AgentHierarchy::firstOrCreate(['ancestor_id' => $directAgent->id, 'descendant_id' => $directAgent->id], ['id' => Str::uuid()->toString(), 'depth' => 0]);
        AgentHierarchy::firstOrCreate(['ancestor_id' => $superAgent->id, 'descendant_id' => $directAgent->id], ['id' => Str::uuid()->toString(), 'depth' => 1]);
        AgentHierarchy::firstOrCreate(['ancestor_id' => $masterAgent->id, 'descendant_id' => $directAgent->id], ['id' => Str::uuid()->toString(), 'depth' => 2]);
    }
}
