<?php

declare(strict_types=1);

namespace App\Services\Network;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/** Trusted server callers only; changes direct assignments, never role permissions. */
final class IpAccessPermissionManager
{
    public function __construct(private readonly AuditLogService $audit) {}

    /** @return array{changed: bool, direct_permission: bool, role_permission: bool, super_admin_role: bool} */
    public function grant(User $actor, User $target, string $reasonCode): array
    {
        return $this->change($actor, $target, $reasonCode, true);
    }

    /** @return array{changed: bool, direct_permission: bool, role_permission: bool, super_admin_role: bool} */
    public function revoke(User $actor, User $target, string $reasonCode): array
    {
        return $this->change($actor, $target, $reasonCode, false);
    }

    private function change(User $actor, User $target, string $reasonCode, bool $grant): array
    {
        return DB::transaction(function () use ($actor, $target, $reasonCode, $grant): array {
            if (! $actor->exists || ! $actor->getKey()) {
                throw new AuthorizationException;
            }
            // Stable lock order for this service; does not lock external role/pivot writers.
            $users = User::query()->whereIn('id', [$actor->getKey(), $target->getKey()])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $operator = $users->get($actor->getKey());
            if (! $operator instanceof User || $operator->status !== UserStatus::Active
                || ! $operator->hasRole('super-admin', 'web') || $actor->getKey() === $target->getKey()) {
                throw new AuthorizationException;
            }
            if (! preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $reasonCode)) {
                throw ValidationException::withMessages(['ip_permission' => 'Invalid reason code.']);
            }
            $recipient = $users->get($target->getKey());
            if (! $target->exists || ! $recipient instanceof User
                || ($grant && ($recipient->status !== UserStatus::Active
                    || ! $recipient->hasRole('admin', 'web') || $recipient->hasRole('super-admin', 'web')))) {
                throw ValidationException::withMessages(['ip_permission' => 'Recipient is not eligible for this operation.']);
            }
            if (config('permission.teams', false) !== false
                || config('permission.enable_wildcard_permission', false) !== false
                || config('permission.events_enabled', false) !== false) {
                throw ValidationException::withMessages(['ip_permission' => 'This service requires standard permissions without teams, wildcards or permission events.']);
            }
            $permission = Permission::query()->where('name', IpAccessRuleManager::MANAGE_PERMISSION)
                ->where('guard_name', 'web')->lockForUpdate()->first();
            if (! $permission instanceof Permission) {
                throw ValidationException::withMessages(['ip_permission' => 'Provision the web permission explicitly before use.']);
            }
            // Spatie reads this relationship during grant; honor strict lazy-loading mode.
            $recipient->load('permissions');
            $before = $this->snapshot($recipient, $permission);
            if ($before['direct_permission'] === $grant) {
                return ['changed' => false, ...$before];
            }
            if ($grant) {
                $recipient->givePermissionTo($permission);
            } else {
                $recipient->revokePermissionTo($permission);
            }
            $after = $this->snapshot($recipient, $permission);
            $this->audit->log(
                userId: (int) $operator->getKey(),
                action: $grant ? AuditAction::PermissionGrant : AuditAction::PermissionRevoke,
                riskLevel: RiskLevel::High, auditable: $recipient,
                description: $grant ? 'Direct IP management permission granted.' : 'Direct IP management permission revoked.',
                metadata: ['schema_version' => 1, 'operation' => $grant ? 'grant_direct' : 'revoke_direct',
                    'permission' => IpAccessRuleManager::MANAGE_PERMISSION, 'guard_name' => 'web', 'reason_code' => $reasonCode],
                oldValues: $before, newValues: $after,
            );

            return ['changed' => true, ...$after];
        });
    }

    private function snapshot(User $recipient, Permission $permission): array
    {
        return [
            'direct_permission' => $recipient->permissions()->whereKey($permission->getKey())->exists(),
            'role_permission' => $recipient->getPermissionsViaRoles()->contains('id', $permission->getKey()),
            'super_admin_role' => $recipient->hasRole('super-admin', 'web'),
        ];
    }
}
