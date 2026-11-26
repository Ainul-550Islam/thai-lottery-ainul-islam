<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the GRANULAR administrative catalogue, the operational roles, and the
 * least-privilege grants that connect them.
 *
 * WHY A SECOND SEEDER INSTEAD OF EDITING RolePermissionSeeder
 *
 * RolePermissionSeeder owns the coarse phrases and the five original roles, and the
 * entire existing panel authorises against its output. Adding granular rows to it
 * would have meant that a deployment which has not run this new seeder yet would
 * silently reshuffle the coarse grants. Keeping the two apart makes each one
 * independently runnable and reversible, and makes this file's blast radius the
 * permissions it explicitly names — nothing else.
 *
 * IDEMPOTENT BY CONSTRUCTION
 *
 *   - Permissions are created with findOrCreate(), never re-created.
 *   - Roles are created with findOrCreate() at guard 'web'.
 *   - Granular grants are ATTACHED, never synced: re-running the seeder cannot remove
 *     a permission an operator granted through the panel in the meantime, and cannot
 *     touch the coarse grants at all.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *
 *   - It does not grant 'users.suspend' to a KYC officer, 'withdrawals.execute' to a
 *     support agent, or 'settings.manage' to anybody but super-admin: the matrix in
 *     config/admin.php is the least-privilege statement, and this file applies it
 *     literally.
 *   - It does not seed super-admin rows. AdminAccess grants super-admin everything
 *     through the Gate bypass; seeding rows would only make the matrix look bigger
 *     than the authority it describes.
 *   - It never creates a user, and never assigns a role to an account.
 */
final class AdminRbacSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Every granular permission exists at guard 'web'.
        foreach (AdminPermission::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // 2. Every declared role exists, so it can be assigned before it is granted.
        /** @var array<string, string> $roles */
        $roles = (array) config('admin.roles', []);

        foreach (array_keys($roles) as $roleName) {
            Role::findOrCreate((string) $roleName, 'web');
        }

        // 3. Apply the least-privilege matrix, granular permissions only.
        foreach (AdminPermission::roleMatrix() as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            foreach ($permissions as $permission) {
                $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }
        }

        // 4. A panel role must be able to reach the panel. canAccessPanel() requires
        //    'view dashboard', which is a coarse phrase owned by RolePermissionSeeder;
        //    the operational roles are not in that seeder's matrix, so they are granted
        //    it here — read-only in every other respect.
        $dashboard = Permission::findOrCreate(AdminAccess::VIEW_DASHBOARD, 'web');

        foreach (AdminAccess::PANEL_ROLES as $roleName) {
            /** @var Role $role `findOrCreate` returns the model, not the contract. */
            $role = Role::findOrCreate($roleName, 'web');

            if (! $role->hasPermissionTo($dashboard)) {
                $role->givePermissionTo($dashboard);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
