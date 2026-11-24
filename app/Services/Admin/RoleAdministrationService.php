<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Exceptions\RoleAdministrationException;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The only writer of roles and role permission grants.
 *
 * WHY THIS EXISTS AS A SERVICE AND NOT INSIDE THE FILAMENT FORM
 *
 * Three separate promises have to hold every time a role changes, and a Filament
 * form is the wrong place to keep them:
 *
 *   1. LEAST PRIVILEGE — an operator may only hand out permissions they hold
 *      themselves. Otherwise 'roles.manage' is a self-escalation primitive: edit a
 *      role you belong to, tick 'withdrawals.execute', done.
 *   2. NO SELF-LOCKOUT — an operator may not remove the ability to administer roles
 *      from a role they themselves hold. Without this rule a click can empty the
 *      panel's admin role and nobody can undo it from the UI.
 *   3. AUDIT — every grant and revocation is written to audit_logs with the actor,
 *      the before/after set and a risk level, because "who made me a finance manager?"
 *      is a question the panel must be able to answer.
 *
 * WHAT THIS SERVICE DELIBERATELY DOES NOT DO
 *
 *   - It never syncs the WHOLE permission set of a role. `syncPermissions()` would
 *     wipe the coarse phrases ('manage payouts', …) that RolePermissionSeeder grants
 *     and that the existing screens authorise against, silently breaking the panel.
 *     It only attaches/detaches permissions from the GRANULAR catalogue and leaves
 *     everything else on the role exactly as it found it.
 *   - It never creates a Role or Permission outside the guard 'web'.
 *   - It never removes a role that is still assigned: deleting an in-use role would
 *     strip access from accounts nobody has decided about.
 */
final class RoleAdministrationService
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Create a role and apply the granular grants the actor is allowed to make.
     *
     * @param  list<string>  $granularPermissions
     *
     * @throws RoleAdministrationException
     */
    public function create(string $name, array $granularPermissions, ?User $actor = null): Role
    {
        $actor ??= AdminAccess::operator();

        $name = $this->normaliseName($name);

        if (Role::query()->where('name', $name)->where('guard_name', 'web')->exists()) {
            throw RoleAdministrationException::malformed(sprintf('A role named [%s] already exists.', $name));
        }

        if (AdminPermission::isProtectedRole($name)) {
            // Protected names are reserved for the seeded roles; re-creating one would
            // produce a second role the panel's entry rule does not know about.
            throw RoleAdministrationException::malformed(sprintf('[%s] is a reserved role name.', $name));
        }

        $grants = $this->assertGrantable($granularPermissions, $actor);

        return DB::transaction(function () use ($name, $grants, $actor): Role {
            /** @var Role $role `findOrCreate` returns the model; the interface type loses the trait. */
            $role = Role::findOrCreate($name, 'web');

            // A freshly created operational role must be able to reach the panel at all,
            // otherwise it is decorative: canAccessPanel() requires 'view dashboard'.
            $dashboard = Permission::findOrCreate(AdminAccess::VIEW_DASHBOARD, 'web');

            if (! $role->hasPermissionTo($dashboard)) {
                $role->givePermissionTo($dashboard);
            }

            $this->attach($role, $grants);

            $this->audit->log(
                userId: $actor?->getKey(),
                action: AuditAction::Create,
                riskLevel: RiskLevel::High,
                auditable: $role,
                description: sprintf('Created administrative role [%s]', $role->name),
                newValues: ['name' => $role->name, 'granular_permissions' => $grants],
            );

            $this->flushPermissionCache();

            return $role->refresh();
        });
    }

    /**
     * Replace the GRANULAR permissions of a role. Coarse grants are untouched.
     *
     * @param  list<string>  $granularPermissions
     * @return array{granted: list<string>, revoked: list<string>}
     *
     * @throws RoleAdministrationException
     */
    public function syncGranularPermissions(Role $role, array $granularPermissions, ?User $actor = null): array
    {
        $actor ??= AdminAccess::operator();

        $this->assertMayEdit($role, $actor);

        $target = $this->assertGrantable($granularPermissions, $actor, $role);

        $current = $this->granularPermissionsOf($role);

        $granted = array_values(array_diff($target, $current));
        $revoked = array_values(array_diff($current, $target));

        if ($granted === [] && $revoked === []) {
            return ['granted' => [], 'revoked' => []];
        }

        $this->assertNoSelfLockout($role, $revoked, $actor);

        return DB::transaction(function () use ($role, $granted, $revoked, $actor, $current, $target): array {
            $this->detach($role, $revoked);
            $this->attach($role, $granted);

            $this->audit->log(
                userId: $actor?->getKey(),
                action: AuditAction::ConfigChange,
                riskLevel: RiskLevel::Critical,
                auditable: $role,
                description: sprintf('Changed granular permissions of role [%s]', $role->name),
                oldValues: ['granular_permissions' => $current],
                newValues: ['granular_permissions' => $target],
                metadata: ['granted' => $granted, 'revoked' => $revoked],
            );

            $this->flushPermissionCache();

            return ['granted' => $granted, 'revoked' => $revoked];
        });
    }

    /**
     * Rename a role.
     *
     * The panel's entry rule is written against role NAMES (AdminAccess::PANEL_ROLES),
     * so a rename of a protected role is what would lock the desk out; those are
     * super-admin only. Operational roles may be renamed freely because nothing in
     * code names them.
     *
     * @throws RoleAdministrationException
     */
    public function rename(Role $role, string $name, ?User $actor = null): Role
    {
        $actor ??= AdminAccess::operator();

        $this->assertMayEdit($role, $actor);

        $name = $this->normaliseName($name);

        if ($name === $role->name) {
            return $role;
        }

        if (AdminPermission::isProtectedRole($name)) {
            throw RoleAdministrationException::malformed(sprintf('[%s] is a reserved role name.', $name));
        }

        if (Role::query()->where('name', $name)->where('guard_name', 'web')->exists()) {
            throw RoleAdministrationException::malformed(sprintf('A role named [%s] already exists.', $name));
        }

        $previous = $role->name;

        return DB::transaction(function () use ($role, $name, $previous, $actor): Role {
            $role->name = $name;
            $role->save();

            $this->audit->log(
                userId: $actor?->getKey(),
                action: AuditAction::ConfigChange,
                riskLevel: RiskLevel::High,
                auditable: $role,
                description: sprintf('Renamed role [%s] to [%s]', $previous, $name),
                oldValues: ['name' => $previous],
                newValues: ['name' => $name],
            );

            $this->flushPermissionCache();

            return $role->refresh();
        });
    }

    /**
     * Delete an unused, unprotected, not-mine role.
     *
     * @throws RoleAdministrationException
     */
    public function delete(Role $role, ?User $actor = null): void
    {
        $actor ??= AdminAccess::operator();

        if (AdminPermission::isProtectedRole($role->name)) {
            throw RoleAdministrationException::protectedRole($role->name);
        }

        $this->assertMayEdit($role, $actor);

        $holders = $role->users()->count();

        if ($holders > 0) {
            throw RoleAdministrationException::roleInUse($role->name, $holders);
        }

        if ($actor instanceof User && $actor->hasRole($role->name)) {
            // Belt and braces: an unused role cannot be one the actor holds, but the
            // check states the intent even if $holders ever becomes a cached count.
            throw RoleAdministrationException::selfLockout($role->name);
        }

        DB::transaction(function () use ($role, $actor): void {
            $name = $role->name;
            $permissions = $role->permissions()->pluck('name')->all();

            $role->permissions()->detach();
            $role->delete();

            $this->audit->log(
                userId: $actor?->getKey(),
                action: AuditAction::Delete,
                riskLevel: RiskLevel::High,
                auditable: null,
                description: sprintf('Deleted administrative role [%s]', $name),
                oldValues: ['name' => $name, 'permissions' => $permissions],
            );

            $this->flushPermissionCache();
        });
    }

    /**
     * The granular permissions currently granted to a role.
     *
     * @return list<string>
     */
    public function granularPermissionsOf(Role $role): array
    {
        $catalogue = AdminPermission::all();

        // `pluck()` hands back mixed; the filter is what makes these strings, so it checks that
        // rather than declaring it. The result is re-indexed because the callers iterate a list.
        return array_values(array_filter(
            $role->permissions()->where('guard_name', 'web')->pluck('name')->all(),
            static fn (mixed $name): bool => is_string($name) && in_array($name, $catalogue, true),
        ));
    }

    /**
     * The coarse permissions a role holds, for display only.
     *
     * @return list<string>
     */
    public function coarsePermissionsOf(Role $role): array
    {
        $catalogue = AdminPermission::all();

        return array_values(array_filter(
            $role->permissions()->where('guard_name', 'web')->pluck('name')->all(),
            static fn (mixed $name): bool => is_string($name) && ! in_array($name, $catalogue, true),
        ));
    }

    /**
     * May this actor edit this role at all?
     */
    public function mayEdit(Role $role, ?User $actor = null): bool
    {
        $actor ??= AdminAccess::operator();

        if (! $actor instanceof User) {
            return false;
        }

        if (! AdminAccess::permits(AdminPermission::ROLES_MANAGE, $actor)) {
            return false;
        }

        if (AdminPermission::isProtectedRole($role->name)) {
            return AdminAccess::isSuperAdmin($actor);
        }

        return true;
    }

    public function mayDelete(Role $role, ?User $actor = null): bool
    {
        $actor ??= AdminAccess::operator();

        // Protected roles are structural: the panel's entry rule and the seeded coarse
        // grants are written against their names, so no operator — super-admin included —
        // gets to delete one. They may be edited; they may not be removed.
        if (AdminPermission::isProtectedRole($role->name)) {
            return false;
        }

        if (! $this->mayEdit($role, $actor)) {
            return false;
        }

        if ($actor instanceof User && $actor->hasRole($role->name)) {
            return false;
        }

        return $role->users()->count() === 0;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @throws RoleAdministrationException
     */
    private function assertMayEdit(Role $role, ?User $actor): void
    {
        if (! $actor instanceof User) {
            throw RoleAdministrationException::malformed('An authenticated operator is required.');
        }

        if (! AdminAccess::permits(AdminPermission::ROLES_MANAGE, $actor)) {
            throw RoleAdministrationException::malformed('You do not hold roles.manage.');
        }

        if (AdminPermission::isProtectedRole($role->name) && ! AdminAccess::isSuperAdmin($actor)) {
            throw RoleAdministrationException::protectedRole($role->name);
        }
    }

    /**
     * Refuse any NEW grant the actor could not make themselves.
     *
     * A permission the role ALREADY holds is not a new power, so leaving it ticked is
     * allowed even when the actor does not hold it — otherwise an operator editing a
     * finance role they do not belong to would be forced to strip it down to their own
     * authority just to rename it. Creating a grant is the escalation; keeping one is
     * not.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     *
     * @throws RoleAdministrationException
     */
    private function assertGrantable(array $permissions, ?User $actor, ?Role $role = null): array
    {
        $out = [];

        foreach ($permissions as $permission) {
            $permission = (string) $permission;

            if (! AdminPermission::exists($permission)) {
                throw RoleAdministrationException::unknownPermission($permission);
            }

            $alreadyHeld = $role instanceof Role && $role->permissions()
                ->where('name', $permission)
                ->where('guard_name', 'web')
                ->exists();

            if (! $alreadyHeld && ! AdminAccess::mayGrant($permission, $actor)) {
                throw RoleAdministrationException::escalation($permission);
            }

            if (! in_array($permission, $out, true)) {
                $out[] = $permission;
            }
        }

        sort($out);

        return $out;
    }

    /**
     * An operator may not edit away their own keys.
     *
     * @param  list<string>  $revoked
     *
     * @throws RoleAdministrationException
     */
    private function assertNoSelfLockout(Role $role, array $revoked, ?User $actor): void
    {
        if (! $actor instanceof User || AdminAccess::isSuperAdmin($actor)) {
            return;
        }

        if (! $actor->hasRole($role->name)) {
            return;
        }

        if (in_array(AdminPermission::ROLES_MANAGE, $revoked, true)) {
            throw RoleAdministrationException::selfLockout($role->name);
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function attach(Role $role, array $permissions): void
    {
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function detach(Role $role, array $permissions): void
    {
        foreach ($permissions as $permission) {
            $role->revokePermissionTo($permission);
        }
    }

    /**
     * @throws RoleAdministrationException
     */
    private function normaliseName(string $name): string
    {
        $name = strtolower(trim($name));

        if (preg_match('/^[a-z][a-z0-9-]{2,49}$/', $name) !== 1) {
            throw RoleAdministrationException::malformed(
                'A role name must be 3-50 characters: lower-case letters, digits and hyphens, starting with a letter.',
            );
        }

        return $name;
    }

    private function flushPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
