<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = (array) config('permission.permissions', []);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $roles = (array) config('permission.roles', []);

        $matrix = [
            'super-admin' => $permissions,
            'admin' => array_values(array_diff($permissions, [
                'manage system settings',
                // GLO-8: admin holds request/review but NOT payment execution
                // or public-status publish — those are dedicated duties.
                'execute glo prize payments',
                'publish glo public status',
            ])),
            'agent' => [
                'view dashboard',
                'view draws',
                'view results',
                'manage wallet',
                'view transaction history',
                'view commissions',
                // Live chat desk: agents answer and claim, but do not move
                // conversations between agents (assign) or administer the
                // saved replies (manage_canned) — those stay with admin.
                'chat.view',
                'chat.reply',
            ],
            'player' => [
                'view dashboard',
                'place bets',
                'view draws',
                'view results',
                'manage wallet',
                'deposit funds',
                'withdraw funds',
                'view transaction history',
            ],
            'auditor' => [
                'view dashboard',
                'view audit logs',
                'view financial reports',
                'view transaction history',
                'reconcile ledger',
                // Read-only: may OBSERVE freeze cases via view audit logs /
                // panel viewAny, but holds NO mutation permission (no review,
                // request, approve, pay or publish).
                //
                // Balance transfers follow the same rule. An auditor needs to
                // READ any player's transfer history to reconcile the clearing
                // account, but `transfer.reverse` MOVES MONEY and
                // `transfer.approve_reverse` DECIDES whether money moves, so both
                // stay with admin and super-admin only. An auditor is deliberately
                // on neither side of the four-eyes lane: the control is worthless
                // if the person whose job is to review it can also operate it.
                'transfer.view_any',
            ],
        ];

        foreach ($roles as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($matrix[$roleName] ?? []);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
