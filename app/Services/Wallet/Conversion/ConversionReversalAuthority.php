<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Enums\UserStatus;
use App\Exceptions\Wallet\ConversionException;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use Illuminate\Support\Facades\DB;

/** Current, transaction-held reversal authority. Never consult a principal relation or permission cache. */
final class ConversionReversalAuthority
{
    public function authorize(int $ownerId, ?int $actorId): User
    {
        if ($ownerId < 1 || $actorId === null || $actorId < 1 || DB::transactionLevel() < 1
            || ! DB::connection()->getPdo()->inTransaction() || config('permission.teams', false)) {
            $this->refuse();
        }
        // Both identities precede conversion/wallet locks; numeric ordering also
        // handles operators reversing each other's conversions without an owner/actor cycle.
        $principals = User::withTrashed()->whereIn('id', array_unique([$ownerId, $actorId]))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $owner = $principals->get($ownerId);
        $actor = $principals->get($actorId);
        if (! $owner || $owner->trashed() || ! $actor || $actor->trashed()
            || UserStatus::tryFrom((string) $actor->getRawOriginal('status')) !== UserStatus::Active) {
            $this->refuse();
        }
        $tables = config('permission.table_names');
        $roleKey = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $permissionKey = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $modelKey = config('permission.column_names.model_morph_key', 'model_id');
        $roleIds = DB::table($tables['model_has_roles'])->where($modelKey, $actorId)
            ->where('model_type', $actor->getMorphClass())->orderBy($roleKey)->lockForUpdate()->pluck($roleKey)->all();
        $roles = DB::table($tables['roles'])->whereIn('id', $roleIds)->orderBy('id')->lockForUpdate()->get();
        if ($roles->count() !== count($roleIds)) {
            $this->refuse();
        }
        $webRoles = $roles->where('guard_name', 'web');
        $names = $webRoles->pluck('name')->all();
        // Preserve the existing super-admin and auditor/admin policy semantics,
        // but require their current WEB identities rather than a cached role name.
        if (in_array('super-admin', $names, true)) {
            return $actor;
        }
        if (in_array('auditor', $names, true) && ! in_array('admin', $names, true)) {
            $this->refuse();
        }
        $direct = DB::table($tables['model_has_permissions'])->where($modelKey, $actorId)
            ->where('model_type', $actor->getMorphClass())->orderBy($permissionKey)->lockForUpdate()->pluck($permissionKey)->all();
        $inherited = DB::table($tables['role_has_permissions'])->whereIn($roleKey, $webRoles->pluck('id')->all())
            ->orderBy($roleKey)->orderBy($permissionKey)->lockForUpdate()->pluck($permissionKey)->all();
        $permissionIds = array_unique([...$direct, ...$inherited]);
        $permissions = DB::table($tables['permissions'])->whereIn('id', $permissionIds)->orderBy('id')->lockForUpdate()->get();
        if ($permissions->count() !== count($permissionIds)) {
            $this->refuse();
        }
        if (! $permissions->contains(fn ($permission) => $permission->guard_name === 'web'
            && in_array($permission->name, [AdminAccess::MANAGE_WALLET, AdminAccess::RECONCILE_LEDGER], true))) {
            $this->refuse();
        }

        return $actor;
    }

    private function refuse(): never
    {
        throw ConversionException::failure('reversal_forbidden', 'Current active operator authority is required before conversion reversal or reversal replay.');
    }
}
