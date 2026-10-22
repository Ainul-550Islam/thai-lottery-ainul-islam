<?php

use Spatie\Permission\DefaultTeamResolver;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return [

    /*
    |--------------------------------------------------------------------------
    | Spatie Permission (top-level keys required by the package)
    |--------------------------------------------------------------------------
    */

    'models' => [
        'permission' => Permission::class,
        'role' => Role::class,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null,
        'permission_pivot_key' => null,
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
    ],

    'register_permission_check_method' => true,
    'register_octane_reset_listener' => false,
    'events_enabled' => false,
    'teams' => false,
    'team_resolver' => DefaultTeamResolver::class,
    'use_passport_client_credentials' => false,
    'display_permission_in_exception' => env('PERMISSION_DISPLAY_IN_EXCEPTION', false),
    'display_role_in_exception' => env('ROLE_DISPLAY_IN_EXCEPTION', false),
    'enable_wildcard_permission' => env('PERMISSION_ENABLE_WILDCARD', false),

    'cache' => [
        'expiration_time' => DateInterval::createFromDateString(
            env('PERMISSION_CACHE_EXPIRATION', '24 hours')
        ),
        // `?:` rather than env()'s default argument. Both committed templates
        // ship this key VALUELESS: ProductionConfigSafetyTest refuses any
        // `*_KEY=` line that carries a value in .env.production.example, and
        // this key is name-shaped like a credential even though it is only the
        // NAME of a cache entry. With env()'s second argument an empty string
        // is a present value, so the default would never be reached and the
        // permission cache would run under an empty key.
        'key' => env('PERMISSION_CACHE_KEY') ?: 'spatie.permission.cache',
        'store' => env('PERMISSION_CACHE_STORE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application role & permission catalogue (project specific)
    |--------------------------------------------------------------------------
    |
    | Consumed by the role/permission seeder. Extra keys are ignored by the
    | Spatie package itself.
    |
    */

    'roles' => [
        'super_admin' => 'super-admin',
        'admin' => 'admin',
        'agent' => 'agent',
        'player' => 'player',
        'auditor' => 'auditor',
    ],

    'permissions' => [
        'view dashboard',
        'place bets',
        'view draws',
        'view results',
        'manage wallet',
        'deposit funds',
        'withdraw funds',
        'view transaction history',
        'manage agents',
        'view commissions',
        'process settlements',
        'manage draws',
        'process draws',
        'manage payouts',
        'view risk alerts',
        'manage risk limits',
        'view audit logs',
        'manage system settings',
        'manage users',
        'view financial reports',
        'reconcile ledger',
        // GLO-8/11/12/14: granular freeze / claim / payment operator duties.
        'request glo freezes',
        'review glo freezes',
        'manage glo prize claims',
        'execute glo prize payments',
        'publish glo public status',
        'review glo dealer requests',
        'manage glo sales points',
        // Live chat desk: five separate grants because they are five separate
        // mistakes when given to the wrong person. The console reads these
        // names through config('chat.operator.permissions').
        'chat.view',
        'chat.reply',
        'chat.assign',
        'chat.close',
        'chat.manage_canned',
        // Balance-transfer desk. Two grants, not one: reading any player's
        // transfer history is a privacy-sensitive power, while reversing a
        // completed transfer MOVES MONEY and must be grantable separately.
        // A player transferring their own balance needs neither - that lane is
        // gated by session auth and by ownership inside the service.
        'transfer.view_any',
        'transfer.reverse',
        // The checker's half of the four-eyes lane on large reversals
        // (config/transfer.php's reversal_approval). Separate from
        // transfer.reverse so the two halves can be granted to different people,
        // and so an installation that wants every reversal double-controlled can
        // hand `reverse` to a wider group and `approve_reverse` to a narrower one.
        // Independence itself is enforced per actor by
        // TransferReversalApprovalService, not by the grant.
        'transfer.approve_reverse',
    ],

];
