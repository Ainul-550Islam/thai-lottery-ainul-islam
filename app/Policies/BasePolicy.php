<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Admin\AdminAccess;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The shared decision for the model policies.
 *
 * WHAT WAS WRONG (and is now fixed)
 *
 * This class used to build an ability as "<prefix>.<method>" — 'wallet.viewAny',
 * 'draw.close' — and ask `$user->can('wallet.viewAny')`. No seeder ever created a
 * permission containing a dot: the seeded catalogue is phrases with spaces
 * ('manage wallet', 'process draws'). So every prefixed policy answered FALSE for every
 * operator who was not a super-admin, and the only thing that ever passed a policy check
 * was the Gate::before bypass. It failed closed — no escalation — but the API's policy
 * layer was in practice super-admin-only, which is not authorisation, it is an outage
 * wearing authorisation's clothes.
 *
 * HOW IT DECIDES NOW
 *
 *   1. An explicit dot ability granted to the user still wins. A deployment that DID seed
 *      'wallet.viewAny' keeps working exactly as before; this is the back-compatible path.
 *   2. Otherwise the ability is mapped, in config/admin.php ('policy_abilities'), to the
 *      granular permission that owns the decision, and the question goes to AdminAccess —
 *      the same single decision point the panel uses. That means the account-status rule,
 *      the super-admin bypass and the granular/coarse bridge all apply here too, instead of
 *      being re-implemented in twenty-seven subclasses.
 *   3. An ability mapped to 'never', or not mapped at all, is DENIED. Writes that belong to
 *      a domain service (create a ledger entry, delete a draw, hard-delete a privacy
 *      request) must not have a policy shortcut, and an unmapped ability must fail closed —
 *      PolicyAbilityMapTest fails the build if a policy method is added without a decision.
 *
 * WHAT POLICIES STILL LEARN FROM `owns()`
 *
 * Ownership is not a permission, so view() implementations keep asking `owns()` first. A
 * player holding no operator permission at all can still read their own bet, wallet or
 * ticket — and only their own — exactly as before.
 */
abstract class BasePolicy
{
    use HandlesAuthorization;

    /**
     * Sentinel value in config/admin.php: no operator may reach this ability via a policy.
     */
    public const NEVER = 'never';

    /**
     * Permission prefix used to build ability names, e.g. "wallet.view".
     */
    protected string $permissionPrefix = '';

    protected function can(User $user, string $ability): bool
    {
        if ($this->permissionPrefix === '') {
            // A policy with no prefix implements its own rule; it must not inherit a
            // permission check it never declared.
            return false;
        }

        $abilityName = $this->permissionPrefix.'.'.$ability;

        if ($this->hasExplicitAbility($user, $abilityName)) {
            return true;
        }

        $mapped = config('admin.policy_abilities')[$abilityName] ?? null;

        if (! is_string($mapped) || $mapped === self::NEVER) {
            return false;
        }

        // STRICT: no bridge to the coarse catalogue. Those phrases include ones players
        // hold ('manage wallet', 'view transaction history'), and a policy with no scoping
        // would turn such a grant into access to every record in the table.
        return $user->isActive() && AdminAccess::permitsStrict($mapped, $user);
    }

    protected function owns(User $user, mixed $model): bool
    {
        return isset($model->user_id) && (int) $model->user_id === (int) $user->getKey();
    }

    /**
     * Was this exact dot ability granted to the user?
     *
     * The permission may not exist at all — that is the normal case, because the catalogue
     * uses phrases — and a missing row is not an error, it is a "no".
     */
    private function hasExplicitAbility(User $user, string $ability): bool
    {
        try {
            return $user->hasPermissionTo($ability, 'web');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
