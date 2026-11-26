<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\User;

/**
 * WHY THIS EXISTS
 *
 * The admin panel needs one answer to "may this operator do this?", and the project
 * arrived with two incompatible ones:
 *
 *   - config('permission.permissions') is a catalogue of human-readable phrases
 *     ('manage draws', 'view audit logs', ...) and RolePermissionSeeder grants exactly
 *     those to the five roles.
 *   - App\Policies\BasePolicy builds dot-prefixed abilities instead ('draw.view',
 *     'wallet.update'), which no seeder ever creates. Every such policy therefore
 *     returns false for everyone except super-admin, who passes through the
 *     Gate::before short-circuit in AuthServiceProvider.
 *
 * That mismatch is recorded as a finding in AUDIT-2026-08-31.md. It is deliberately NOT
 * "fixed" by renaming permissions here: the policies are covered by existing tests and
 * drive the API surface, so changing their vocabulary is a separate, breaking decision.
 *
 * Instead the panel authorizes against the seeded catalogue - the vocabulary the roles
 * actually carry - through this one class. Every Filament resource and page asks these
 * methods and nothing else, so the panel's authorization model is auditable in a single
 * file rather than spread across forty resource classes.
 *
 * WHAT IS DELIBERATELY NOT DONE HERE
 * - No permission is created, granted or revoked. This class only reads.
 * - No role hierarchy is invented beyond the super-admin bypass Laravel already applies.
 * - Nothing here decides whether a domain operation is *legal* (a draw can only be
 *   closed from Open, a deposit can only be approved once). That stays in the domain
 *   services; this only decides who is allowed to ask.
 */
final class AdminAccess
{
    /**
     * Roles that may reach the panel at all.
     *
     * 'agent' and 'player' are excluded on purpose: the agent portal is Phase 9 and is
     * not this panel.
     *
     * The operational roles below ('finance-manager' … 'read-only-analyst') were added
     * with the granular catalogue so that a KYC officer no longer has to be handed the
     * 'admin' role — and with it the power to approve withdrawals — in order to review a
     * passport. A role being listed here is NECESSARY but not sufficient: canAccessPanel()
     * still requires an active account and the 'view dashboard' permission, which
     * AdminRbacSeeder grants to every role it creates.
     *
     * @var list<string>
     */
    public const PANEL_ROLES = [
        'super-admin',
        'admin',
        'auditor',
        'finance-manager',
        'deposit-manager',
        'withdrawal-manager',
        'kyc-officer',
        'aml-officer',
        'fraud-analyst',
        'support-agent',
        'lottery-manager',
        'read-only-analyst',
    ];

    // The seeded permission catalogue, as constants so a typo is a fatal error rather
    // than a silently denied (or silently granted) screen.
    public const VIEW_DASHBOARD = 'view dashboard';

    public const VIEW_DRAWS = 'view draws';

    public const MANAGE_DRAWS = 'manage draws';

    public const PROCESS_DRAWS = 'process draws';

    public const VIEW_RESULTS = 'view results';

    public const PROCESS_SETTLEMENTS = 'process settlements';

    public const MANAGE_PAYOUTS = 'manage payouts';

    public const VIEW_TRANSACTION_HISTORY = 'view transaction history';

    public const VIEW_FINANCIAL_REPORTS = 'view financial reports';

    public const RECONCILE_LEDGER = 'reconcile ledger';

    public const MANAGE_WALLET = 'manage wallet';

    public const MANAGE_USERS = 'manage users';

    public const MANAGE_AGENTS = 'manage agents';

    public const VIEW_COMMISSIONS = 'view commissions';

    public const VIEW_RISK_ALERTS = 'view risk alerts';

    public const MANAGE_RISK_LIMITS = 'manage risk limits';

    public const VIEW_AUDIT_LOGS = 'view audit logs';

    public const MANAGE_SYSTEM_SETTINGS = 'manage system settings';

    // GLO-8/11/12/14 freeze, claim and payment operator duties.
    public const REQUEST_GLO_FREEZES = 'request glo freezes';

    public const REVIEW_GLO_FREEZES = 'review glo freezes';

    public const MANAGE_GLO_PRIZE_CLAIMS = 'manage glo prize claims';

    public const EXECUTE_GLO_PRIZE_PAYMENTS = 'execute glo prize payments';

    public const PUBLISH_GLO_PUBLIC_STATUS = 'publish glo public status';

    // GLO-15/16 additive permissions (seeded via config/permission.php).
    public const REVIEW_GLO_DEALER_REQUESTS = 'review glo dealer requests';

    public const MANAGE_GLO_SALES_POINTS = 'manage glo sales points';

    /**
     * Which seeded permission a console panel requires.
     *
     * @param  string  $panel  a value produced by the console's panel resolver
     */
    public static function panelPermission(string $panel): string
    {
        return match ($panel) {
            'draws' => self::VIEW_DRAWS,
            'risk', 'compliance' => self::VIEW_RISK_ALERTS,
            'payments', 'withdrawals' => self::MANAGE_PAYOUTS,
            'bets' => self::VIEW_TRANSACTION_HISTORY,
            'audits' => self::VIEW_AUDIT_LOGS,
            'wallets' => self::MANAGE_WALLET,
            'ledger' => self::VIEW_FINANCIAL_REPORTS,
            'reconciliation' => self::RECONCILE_LEDGER,
            'kyc' => self::MANAGE_USERS,
            'claims', 'claim_review' => self::MANAGE_GLO_PRIZE_CLAIMS,
            'freezes' => self::REVIEW_GLO_FREEZES,
            'settlements' => self::PROCESS_SETTLEMENTS,
            'wallet_operations' => self::MANAGE_WALLET,
            'payment_methods', 'withdrawal_methods', 'payment_events', 'payment_exceptions' => self::MANAGE_PAYOUTS,
            'draw_lifecycle' => self::MANAGE_DRAWS,
            'publication', 'imports', 'sources' => self::VIEW_RESULTS,
            'lotteries', 'lottery_rules', 'fees', 'account_grades' => self::MANAGE_SYSTEM_SETTINGS,
            'account_verification', 'responsible_gaming', 'self_exclusion', 'users', 'user_detail', 'user_finance', 'bet_detail', 'ticket_detail', 'ticket_verification' => self::MANAGE_USERS,
            'commissions' => self::VIEW_COMMISSIONS,
            'queues', 'scheduler', 'runtime', 'api_status', 'webhooks', 'security', 'release', 'cutover', 'health' => self::VIEW_AUDIT_LOGS,
            default => self::VIEW_DASHBOARD,
        };
    }

    /**
     * Every panel this class knows about, keyed by the permission it requires.
     *
     * @return array<string, string>
     */
    public static function panelPermissions(): array
    {
        $panels = [
            'dashboard', 'draws', 'risk', 'compliance', 'payments', 'withdrawals', 'bets',
            'audits', 'wallets', 'ledger', 'reconciliation', 'kyc', 'claims', 'claim_review',
            'freezes', 'settlements', 'wallet_operations', 'payment_methods',
            'withdrawal_methods', 'payment_events', 'payment_exceptions', 'draw_lifecycle',
            'publication', 'imports', 'sources', 'lotteries', 'lottery_rules', 'fees',
            'account_grades', 'account_verification', 'responsible_gaming', 'self_exclusion',
            'users', 'user_detail', 'user_finance', 'bet_detail', 'ticket_detail',
            'ticket_verification', 'commissions', 'queues', 'scheduler', 'runtime',
            'api_status', 'webhooks', 'security', 'release', 'cutover', 'health',
        ];

        $out = [];

        foreach ($panels as $panel) {
            $out[$panel] = self::panelPermission($panel);
        }

        return $out;
    }

    /**
     * May this user open THIS panel?
     */
    public static function mayOpenPanel(?User $user, string $panel): bool
    {
        return self::canAccessPanel($user) && self::allows($user, self::panelPermission($panel));
    }

    /**
     * May this user open the admin panel?
     *
     * Three conditions, all of them necessary: an operator role, an account status that
     * permits login (so a suspended admin loses the panel the moment they are
     * suspended, not at the next session), and the dashboard permission.
     */
    public static function canAccessPanel(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        // Deliberately stricter than UserStatus::canLogin(), which also admits
        // PendingVerification. A player may browse with an unverified email; an operator
        // may not publish an official result or approve a withdrawal from an account
        // nobody has confirmed belongs to them. Only a fully Active account gets in.
        if (! $user->status->canTransact()) {
            return false;
        }

        if (! $user->hasAnyRole(self::PANEL_ROLES)) {
            return false;
        }

        return self::allows($user, self::VIEW_DASHBOARD);
    }

    /**
     * Does this user hold the given seeded permission?
     *
     * super-admin is allowed everything, mirroring the Gate::before rule the rest of
     * the application already relies on.
     */
    public static function allows(?User $user, string $permission): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if (! $user->status->canLogin()) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return true;
        }

        try {
            return $user->hasPermissionTo($permission, 'web');
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            // A missing permission registration is not authorization; fail closed.
            return false;
        }
    }

    /**
     * Does this user hold at least one of the given permissions?
     *
     * @param  list<string>  $permissions
     */
    public static function allowsAny(?User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::allows($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this operator the super-admin?
     *
     * WHY THIS IS A METHOD AND NOT A hasRole() CALL AT THE CALL SITE
     *
     * The panel's own hardening suite fails any Filament class that inspects a role
     * name directly, because that is how a screen ends up with a second, drifting
     * authorisation rule. Screens that need "am I allowed to do this at all?" ask
     * here instead.
     */
    public static function isSuperAdmin(?User $user = null): bool
    {
        $user ??= self::operator();

        return $user instanceof User && $user->hasRole('super-admin');
    }

    /**
     * Does this operator hold a GRANULAR administrative permission?
     *
     * THE TRANSITION CONTRACT (see config/admin.php)
     *
     *   enforce = true                  -> only a granular grant counts.
     *   enforce = false, fallback = true -> a granular grant OR the coarse phrase the
     *                                      granular permission replaces.
     *   enforce = false, fallback = false -> only a granular grant counts.
     *
     * The fallback exists because five of the panel's screens still ask the coarse
     * question; without it, migrating one screen would strip a working role of access
     * to it. It is a bridge, not a destination, and it is switched off by config rather
     * than by editing code.
     */
    public static function permits(string $granularPermission, ?User $user = null): bool
    {
        $user ??= self::operator();

        if (! $user instanceof User) {
            return false;
        }

        if (! $user->status->canLogin()) {
            return false;
        }

        // THE OPERATOR PRECONDITION
        //
        // A granular permission is a PANEL power: it exists to open a screen or a verb on one.
        // The coarse catalogue it replaces is shared with players ('manage wallet',
        // 'view transaction history', 'view draws'), so the bridge alone would let a player
        // who holds those phrases pass a resource's canViewAny() — harmless behind the panel's
        // entry gate, but a second line of defence is cheaper than trusting one gate forever.
        //
        // Being an operator (a panel role, an account that may still sign in, and the
        // dashboard permission) is therefore necessary for every granular check. It is not
        // sufficient: the grant still has to be there.
        if (! self::canAccessPanel($user)) {
            return false;
        }

        // A name outside the catalogue can never be granted, so it must never be
        // honoured — not even for a super-admin, and not even if a stale row carries it.
        // This check therefore stands BEFORE the bypass: "super-admin may do anything"
        // must not stretch to "anything, including things that are not powers at all".
        if (! AdminPermission::exists($granularPermission)) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return true;
        }

        if (self::holdsGranular($user, $granularPermission)) {
            return true;
        }

        if (self::granularRbacEnforced()) {
            return false;
        }

        if (! self::legacyFallbackEnabled()) {
            return false;
        }

        $legacy = AdminPermission::legacyEquivalent($granularPermission);

        return $legacy !== null && self::allows($user, $legacy);
    }

    /**
     * Does this operator hold a granular permission, WITHOUT the coarse fallback?
     *
     * WHY THIS EXISTS — AND WHY `permits()` MUST NOT BE USED FOR POLICY CHECKS
     *
     * The coarse catalogue contains phrases that PLAYERS hold: 'manage wallet',
     * 'view transaction history', 'view draws', 'view results'. They were written for
     * player-facing screens, where the screen itself scopes the query to the signed-in
     * player.
     *
     * A model policy has no such scoping: WalletPolicy::viewAny() is a blanket "may list
     * every wallet". Bridging 'wallet.viewAny' to 'manage wallet' through the legacy
     * fallback therefore hands every player the right to enumerate the whole wallet table
     * (proven by PolicyAbilityMapTest::test_a_player_still_holds_no_operator_ability, which
     * failed exactly that way before this method existed).
     *
     * So: screen authorisation may bridge to the coarse vocabulary; record-level policy
     * authorisation may not. The difference is not stylistic — it is the difference
     * between "this screen is yours" and "this table is yours".
     */
    public static function permitsStrict(string $granularPermission, ?User $user = null): bool
    {
        $user ??= self::operator();

        if (! $user instanceof User) {
            return false;
        }

        if (! $user->status->canLogin()) {
            return false;
        }

        if (! AdminPermission::exists($granularPermission)) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return true;
        }

        return self::holdsGranular($user, $granularPermission);
    }

    /**
     * Does this operator hold ANY of the given granular permissions?
     *
     * @param  list<string>  $granularPermissions
     */
    public static function permitsAny(array $granularPermissions, ?User $user = null): bool
    {
        foreach ($granularPermissions as $permission) {
            if (self::permits($permission, $user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * May this operator grant this granular permission to somebody else?
     *
     * Least privilege has a second half that is easy to forget: an operator must not be
     * able to hand out a power they do not hold. A KYC officer who may manage roles can
     * therefore only compose roles out of KYC-shaped permissions, and cannot grant
     * 'withdrawals.execute' — or escalate themselves — by editing a role they belong to.
     *
     * Super-admin is exempt because it already holds everything by definition.
     */
    public static function mayGrant(string $granularPermission, ?User $user = null): bool
    {
        $user ??= self::operator();

        if (! $user instanceof User) {
            return false;
        }

        if (self::isSuperAdmin($user)) {
            return AdminPermission::exists($granularPermission);
        }

        return AdminPermission::exists($granularPermission)
            && self::permits($granularPermission, $user);
    }

    /**
     * The granular permissions this operator may hand out, for a checkbox list.
     *
     * @return list<string>
     */
    public static function grantable(?User $user = null): array
    {
        $user ??= self::operator();

        if (! $user instanceof User) {
            return [];
        }

        return array_values(array_filter(
            AdminPermission::all(),
            static fn (string $permission): bool => self::mayGrant($permission, $user),
        ));
    }

    private static function holdsGranular(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission, 'web');
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            // Not seeded yet is not a grant.
            return false;
        }
    }

    public static function granularRbacEnforced(): bool
    {
        return (bool) config('admin.granular_rbac.enforce', false);
    }

    public static function legacyFallbackEnabled(): bool
    {
        return (bool) config('admin.granular_rbac.fallback_to_legacy', true);
    }

    /**
     * The authenticated panel user, or null.
     */
    public static function operator(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Convenience wrapper for resources: does the current operator hold this permission?
     */
    public static function current(string $permission): bool
    {
        return self::allows(self::operator(), $permission);
    }

    /**
     * @param  list<string>  $permissions
     */
    public static function currentAny(array $permissions): bool
    {
        return self::allowsAny(self::operator(), $permissions);
    }

    /**
     * A read-only operator is one who may look at money but not move it.
     *
     * The 'auditor' role exists precisely for this, and the panel must never present it
     * a button it is not allowed to press.
     */
    public static function isReadOnly(?User $user = null): bool
    {
        $user ??= self::operator();

        if (! $user instanceof User) {
            return true;
        }

        if ($user->hasRole('super-admin')) {
            return false;
        }

        return $user->hasRole('auditor') && ! $user->hasAnyRole(['admin']);
    }

    /**
     * What this class does and does not decide, for the record.
     *
     * @return array<string, mixed>
     */
    public static function audit(): array
    {
        return [
            'vocabulary' => 'config(permission.permissions) + config(admin.permission_groups)',
            'granular_vocabulary' => AdminPermission::all(),
            'granular_enforced' => self::granularRbacEnforced(),
            'granular_legacy_fallback' => self::legacyFallbackEnabled(),
            'grants_permissions' => false,
            'writes_anything' => false,
            'super_admin_bypass' => true,
            'panel_roles' => self::PANEL_ROLES,
            'known_divergence' => 'App\\Policies\\BasePolicy uses dot-prefixed abilities that are never seeded.',
        ];
    }
}
