<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Order matters only in the sense that both are prerequisites for anything
            // else: no user can act without a role, and no financial movement can be
            // posted without the chart of accounts LedgerPostingService resolves against.
            RolePermissionSeeder::class,
            // Granular admin permission catalogue + operational roles (additive; runs after the coarse seeder).
            AdminRbacSeeder::class,
            LedgerAccountSeeder::class,
            // Optional historical result archive: a no-op unless per-draw
            // payload files exist under database/seeders/data/results.
            // See ResultArchiveSeeder for why this is data-driven and not
            // an invented history.
            ResultArchiveSeeder::class,
            CashbackPolicySeeder::class,
            RewardCatalogSeeder::class,
            // Batch 2: registers speed_88 and lao_hanoi_4d with the operator layer.
            // Both are created rules_status=unverified and enabled=false, so this is
            // a no-op for gameplay: no draw, no market and no payout path becomes
            // reachable until an operator verifies the rules and enables the game.
            LotteryGamesSeeder::class,
        ]);
    }
}
