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
            LedgerAccountSeeder::class,
            // Optional historical result archive: a no-op unless per-draw
            // payload files exist under database/seeders/data/results.
            // See ResultArchiveSeeder for why this is data-driven and not
            // an invented history.
            ResultArchiveSeeder::class,
        ]);
    }
}
