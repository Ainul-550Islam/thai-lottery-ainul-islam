<?php

declare(strict_types=1);

namespace App\Support\Admin;

use InvalidArgumentException;

/**
 * The granular administrative permission catalogue, as code.
 *
 * WHY THIS EXISTS
 *
 * config/admin.php holds the catalogue as data so an operator can extend it
 * without a deploy-time class edit. That is the right place for the LIST, but
 * the wrong place for a NAME: `AdminAccess::permits('users.suspnd')` would
 * silently deny forever, and a typo in a role matrix would silently grant
 * nothing. This class turns every catalogued name into a constant, so a typo is
 * a fatal error at the call site instead of an invisible refusal at 3am.
 *
 * WHAT IT DOES NOT DO
 *
 * It grants nothing, writes nothing and caches nothing. Grants live in the
 * database (spatie/laravel-permission) and are applied by AdminRbacSeeder.
 * Authorisation decisions belong to AdminAccess; this class only answers
 * "what is the catalogue?" and "what label does name X carry?".
 *
 * The constants are the union of config('admin.permission_groups'); the
 * test AdminRbacCatalogueTest fails if the two ever disagree in either
 * direction, which is what keeps the "typo is fatal" promise true.
 */
final class AdminPermission
{
    // Users -----------------------------------------------------------------
    public const USERS_VIEW = 'users.view';

    public const USERS_UPDATE = 'users.update';

    public const USERS_SUSPEND = 'users.suspend';

    public const USERS_VERIFY = 'users.verify';

    public const AGENTS_VIEW = 'agents.view';

    public const AGENTS_MANAGE = 'agents.manage';

    public const COMMISSIONS_VIEW = 'commissions.view';

    // Betting ---------------------------------------------------------------
    public const BETS_VIEW = 'bets.view';

    public const BETS_MANAGE = 'bets.manage';

    // Deposits --------------------------------------------------------------
    // Risk limits: the number-limit screens' vocabulary. Granted to the same operators who
    // already held 'view risk alerts' / 'manage risk limits' as coarse phrases.
    public const RISK_LIMITS_VIEW = 'risk.limits.view';

    public const RISK_LIMITS_MANAGE = 'risk.limits.manage';

    // GLO operator duties (GLO-8/11/12/14/15/16). Splitting them is the point: executing a
    // prize payment and publishing the public status are NOT implied by working a claim.
    public const GLO_FREEZES_REQUEST = 'glo.freezes.request';

    public const GLO_FREEZES_REVIEW = 'glo.freezes.review';

    public const GLO_CLAIMS_MANAGE = 'glo.claims.manage';

    public const GLO_CLAIMS_PAY = 'glo.claims.pay';

    public const GLO_STATUS_PUBLISH = 'glo.status.publish';

    public const GLO_DEALERS_REVIEW = 'glo.dealers.review';

    public const GLO_SALESPOINTS_MANAGE = 'glo.salespoints.manage';

    public const DEPOSITS_VIEW = 'deposits.view';

    public const DEPOSITS_REVIEW = 'deposits.review';

    public const DEPOSITS_APPROVE = 'deposits.approve';

    public const DEPOSITS_REJECT = 'deposits.reject';

    // Withdrawals -----------------------------------------------------------
    public const WITHDRAWALS_VIEW = 'withdrawals.view';

    public const WITHDRAWALS_REVIEW = 'withdrawals.review';

    public const WITHDRAWALS_APPROVE = 'withdrawals.approve';

    public const WITHDRAWALS_REJECT = 'withdrawals.reject';

    public const WITHDRAWALS_EXECUTE = 'withdrawals.execute';

    // Wallets and ledger ----------------------------------------------------
    public const WALLETS_VIEW = 'wallets.view';

    public const WALLETS_ADJUST = 'wallets.adjust';

    public const WALLETS_RECONCILE = 'wallets.reconcile';

    public const LEDGER_VIEW = 'ledger.view';

    public const LEDGER_RECONCILE = 'ledger.reconcile';

    // Payment providers -----------------------------------------------------
    public const PAYMENTS_VIEW = 'payments.view';

    public const PROVIDERS_VIEW = 'payments.providers.view';

    public const PROVIDERS_MANAGE = 'payments.providers.manage';

    // Compliance ------------------------------------------------------------
    public const PRIVACY_VIEW = 'privacy.view';

    public const PRIVACY_MANAGE = 'privacy.manage';

    public const KYC_VIEW = 'kyc.view';

    public const KYC_REVIEW = 'kyc.review';

    public const KYC_APPROVE = 'kyc.approve';

    public const AML_VIEW = 'aml.view';

    public const AML_REVIEW = 'aml.review';

    public const FRAUD_VIEW = 'fraud.view';

    public const FRAUD_REVIEW = 'fraud.review';

    // Support ---------------------------------------------------------------
    public const SUPPORT_VIEW = 'support.view';

    public const SUPPORT_MANAGE = 'support.manage';

    // Lottery ---------------------------------------------------------------
    public const GAMES_VIEW = 'lottery.games.view';

    public const GAMES_MANAGE = 'lottery.games.manage';

    public const GAMES_PUBLISH = 'lottery.games.publish';

    // Reporting -------------------------------------------------------------
    public const REPORTS_VIEW = 'reports.view';

    public const REPORTS_EXPORT = 'reports.export';

    // Administration --------------------------------------------------------
    public const ROLES_VIEW = 'roles.view';

    public const ROLES_MANAGE = 'roles.manage';

    public const PERMISSIONS_VIEW = 'permissions.view';

    public const SETTINGS_VIEW = 'settings.view';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const AUDIT_LOGS_VIEW = 'audit_logs.view';

    /**
     * Every catalogued granular permission, flat.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $all = [];

        foreach (self::groups() as $permissions) {
            foreach (array_keys($permissions) as $name) {
                $all[] = $name;
            }
        }

        return array_values(array_unique($all));
    }

    /**
     * The catalogue grouped for a checkbox list: group label => [name => label].
     *
     * @return array<string, array<string, string>>
     */
    public static function groups(): array
    {
        /** @var array<string, array<string, string>> $groups */
        $groups = (array) config('admin.permission_groups', []);

        return $groups;
    }

    /**
     * The human label for a permission, falling back to the machine name so a
     * row that predates the catalogue still renders something honest.
     */
    public static function label(string $permission): string
    {
        foreach (self::groups() as $permissions) {
            if (isset($permissions[$permission])) {
                return (string) $permissions[$permission];
            }
        }

        return $permission;
    }

    /**
     * The group a permission belongs to, or 'Uncatalogued'.
     */
    public static function groupFor(string $permission): string
    {
        foreach (self::groups() as $group => $permissions) {
            if (isset($permissions[$permission])) {
                return (string) $group;
            }
        }

        return 'Uncatalogued';
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /**
     * The coarse phrase this granular permission supersedes, if any.
     *
     * Null means "no legacy equivalent": the permission is granted by its own
     * grant only, and a granular check for it is refused while the fallback is
     * the only mechanism in play. That is deliberate — inventing a legacy
     * equivalent would widen access during the migration.
     */
    public static function legacyEquivalent(string $permission): ?string
    {
        /** @var array<string, string|null> $map */
        $map = (array) config('admin.legacy_fallback', []);

        $value = $map[$permission] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The seeded role => granular permission matrix.
     *
     * @return array<string, list<string>>
     */
    public static function roleMatrix(): array
    {
        /** @var array<string, list<string>> $matrix */
        $matrix = (array) config('admin.role_matrix', []);

        return $matrix;
    }

    /**
     * The label for a role, or the role name when it is not catalogued.
     */
    public static function roleLabel(string $role): string
    {
        /** @var array<string, string> $roles */
        $roles = (array) config('admin.roles', []);

        return (string) ($roles[$role] ?? $role);
    }

    /**
     * @return list<string>
     */
    public static function protectedRoles(): array
    {
        /** @var list<string> $roles */
        $roles = array_values((array) config('admin.protected_roles', []));

        return $roles;
    }

    public static function isProtectedRole(string $role): bool
    {
        return in_array($role, self::protectedRoles(), true);
    }

    /**
     * Guard for call sites that take a permission name from a request or a
     * database row. An unknown name is a programming error, not a refusal.
     */
    public static function assertExists(string $permission): void
    {
        if (! self::exists($permission)) {
            throw new InvalidArgumentException(
                sprintf('[%s] is not in the granular permission catalogue (config/admin.php).', $permission),
            );
        }
    }
}
