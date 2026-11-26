<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Administrative access configuration
|--------------------------------------------------------------------------
|
| WHY THIS FILE EXISTS
|
| The operator panel arrived with a coarse permission catalogue in
| config/permission.php — one phrase per SCREEN ("manage users", "manage
| payouts"). That vocabulary answers "which screen may I open?" but not
| "which BUTTON may I press?", so a KYC officer and a finance manager were
| indistinguishable: both had to be given 'manage users', which also carries
| the power to suspend accounts.
|
| This file holds the second, GRANULAR vocabulary
| (`users.view`, `deposits.approve`, `kyc.approve`, ...), the roles that
| carry it, and the bridge that maps every granular permission back to the
| coarse phrase it replaces. Nothing here grants anything by itself; the
| grants are applied by Database\Seeders\AdminRbacSeeder and read by
| App\Support\Admin\AdminPermission / AdminAccess.
|
| THE TRANSITION RULE
|
| Existing screens still authorise against the coarse catalogue. Rewriting
| all of them in the same change would have made every one of them untested
| in the same commit. Instead:
|
|   - `granular_rbac.fallback_to_legacy` (default true) lets a granular
|     check succeed when the operator holds the coarse phrase the granular
|     permission replaces. Behaviour of today's roles is therefore unchanged.
|   - `granular_rbac.enforce` (default false) makes granular grants the ONLY
|     thing that counts, without falling back. Flip it only after every
|     screen has been migrated.
|
| Both switches exist so the migration is decided by an operator, not by a
| side effect of deploying this file.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Granular RBAC switches
    |--------------------------------------------------------------------------
    */
    'granular_rbac' => [

        // When true a granular grant is the only thing that authorises a granular
        // check: no fallback to the coarse phrase. Off by default because the
        // existing screens still ask the coarse question.
        'enforce' => (bool) env('ADMIN_GRANULAR_RBAC_ENFORCE', false),

        // When true (and `enforce` is false) an operator holding the coarse phrase
        // a granular permission replaces is treated as holding the granular one.
        // This is what keeps the seeded roles working while screens migrate.
        'fallback_to_legacy' => (bool) env('ADMIN_GRANULAR_RBAC_FALLBACK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    |
    | The operational roles this panel recognises, with the human label shown in
    | the role manager. The first five already exist in config/permission.php and
    | are seeded by RolePermissionSeeder; the rest are created by
    | AdminRbacSeeder and exist so that a KYC officer no longer has to be an
    | admin to review a passport.
    |
    | A role listed here is NOT automatically allowed into the panel: entry is
    | still AdminAccess::canAccessPanel(), which requires a panel role, an
    | active account and 'view dashboard'.
    |
    */
    'roles' => [
        'super-admin' => 'Super Admin',
        'admin' => 'Administrator',
        'auditor' => 'Auditor (read only)',
        'finance-manager' => 'Finance Manager',
        'deposit-manager' => 'Deposit Manager',
        'withdrawal-manager' => 'Withdrawal Manager',
        'kyc-officer' => 'KYC Officer',
        'aml-officer' => 'AML Officer',
        'fraud-analyst' => 'Fraud Analyst',
        'support-agent' => 'Customer Support',
        'lottery-manager' => 'Lottery Manager',
        'read-only-analyst' => 'Read-only Analyst',
    ],

    /*
    |--------------------------------------------------------------------------
    | System settings catalogue (the only keys an operator may override)
    |--------------------------------------------------------------------------
    |
    | This is a WHITELIST, not a menu. A key that is not listed here cannot be
    | written by the panel: App\Services\Admin\SystemSettingService refuses it
    | by name. Each entry declares the type, the bounds and the group the
    | settings page draws — so the validation rule and the form are the same
    | declaration, and neither can drift from the other.
    |
    | WHAT IS DELIBERATELY ABSENT
    |
    | Nothing secret, ever: APP_KEY, database credentials, mail credentials and
    | every payment gateway secret stay in the environment. A settings table an
    | operator can write must never become a place a token is read from, and a
    | row here is readable by anyone who can read the database.
    |
    | 'money' values are decimal strings and are validated as strings; the pair
    | keys (a minimum and its maximum) are cross-checked against each other so a
    | minimum above its maximum cannot be stored.
    |
    */
    'settings_catalogue' => [

        'lottery.draw.interval_minutes' => [
            'label' => 'Draw interval (minutes)',
            'group' => 'Lottery automation',
            'type' => 'int',
            'min' => 5,
            'max' => 1440,
            'description' => 'How far apart scheduled draws are placed by the scheduler. Changing it affects only draws created after the change.',
        ],

        'lottery.closing.minutes_before_draw' => [
            'label' => 'Betting closes (minutes before the draw)',
            'group' => 'Lottery automation',
            'type' => 'int',
            'min' => 0,
            'max' => 120,
            'description' => 'The closing window the draw engine applies when it closes betting.',
        ],

        'lottery.automation.enabled' => [
            'label' => 'Automation enabled',
            'group' => 'Lottery automation',
            'type' => 'bool',
            'description' => 'The master switch for the scheduled automation lane (open/close/draw/publish ticks).',
        ],

        'lottery.automation.auto_settle' => [
            'label' => 'Settle winners automatically',
            'group' => 'Lottery automation',
            'type' => 'bool',
            'description' => 'Whether the settlement lane pays winners without a second operator action.',
        ],

        'lottery.automation.batch_size' => [
            'label' => 'Automation batch size',
            'group' => 'Lottery automation',
            'type' => 'int',
            'min' => 1,
            'max' => 500,
            'description' => 'Rows handled per automation tick. Larger batches finish sooner and hold locks longer.',
        ],

        'lottery.automation.horizon_days' => [
            'label' => 'Schedule horizon (days)',
            'group' => 'Lottery automation',
            'type' => 'int',
            'min' => 1,
            'max' => 365,
            'description' => 'How many days of draws the scheduler keeps created ahead of time.',
        ],

        'finance.withdrawal.processing_hours' => [
            'label' => 'Withdrawal processing promise (hours)',
            'group' => 'Finance',
            'type' => 'int',
            'min' => 1,
            'max' => 168,
            'description' => 'The service window quoted to players. It is a promise, not a timer: nothing pays out because of it.',
        ],

        'finance.deposit.min' => [
            'label' => 'Minimum deposit',
            'group' => 'Finance',
            'type' => 'money',
            'min' => '1.00',
            'max' => '1000000.00',
            'pair' => 'finance.deposit.max',
            'description' => 'Refused below this amount before any provider is contacted. Must not exceed the maximum deposit.',
        ],

        'finance.deposit.max' => [
            'label' => 'Maximum deposit',
            'group' => 'Finance',
            'type' => 'money',
            'min' => '1.00',
            'max' => '1000000.00',
            'pair' => 'finance.deposit.min',
            'description' => 'Refused above this amount before any provider is contacted. Must not be below the minimum deposit.',
        ],

        'finance.withdrawal.min' => [
            'label' => 'Minimum withdrawal',
            'group' => 'Finance',
            'type' => 'money',
            'min' => '1.00',
            'max' => '1000000.00',
            'pair' => 'finance.withdrawal.max',
            'description' => 'Refused below this amount. Must not exceed the maximum withdrawal.',
        ],

        'finance.withdrawal.max' => [
            'label' => 'Maximum withdrawal',
            'group' => 'Finance',
            'type' => 'money',
            'min' => '1.00',
            'max' => '1000000.00',
            'pair' => 'finance.withdrawal.min',
            'description' => 'Refused above this amount. Must not be below the minimum withdrawal.',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Protected roles
    |--------------------------------------------------------------------------
    |
    | Roles that the role manager refuses to delete, and refuses to edit unless
    | the actor is a super-admin. 'admin' and 'auditor' are here because the
    | panel's entry rule (AdminAccess::PANEL_ROLES) is written against them: a
    | renamed or emptied 'admin' role would silently lock the desk out.
    |
    */
    'protected_roles' => ['super-admin', 'admin', 'auditor'],

    /*
    |--------------------------------------------------------------------------
    | Granular permission catalogue
    |--------------------------------------------------------------------------
    |
    | Grouped by the surface that owns the decision. Each entry is
    | machine-name => human label. A permission that is not listed here cannot
    | be granted through the role manager: the catalogue is the whitelist.
    |
    */
    'permission_groups' => [

        'Users' => [
            'users.view' => 'View user records',
            'users.update' => 'Update user profile / notes',
            'users.suspend' => 'Suspend or reactivate an account',
            'users.verify' => 'Review account verification',
            'agents.view' => 'View agent records and hierarchy',
            'agents.manage' => 'Create or change an agent',
            'commissions.view' => 'View the commission ledger',
        ],

        'Betting' => [
            'bets.view' => 'View bets and tickets',
            'bets.manage' => 'Amend, cancel or share a bet / ticket',
        ],

        'Risk limits' => [
            'risk.limits.view' => 'View betting number limits',
            'risk.limits.manage' => 'Change a betting number limit',
        ],

        'GLO operations' => [
            'glo.freezes.request' => 'Request a GLO ticket freeze',
            'glo.freezes.review' => 'Approve or reject a GLO freeze',
            'glo.claims.manage' => 'Work a GLO prize claim',
            'glo.claims.pay' => 'Execute a GLO prize payment',
            'glo.status.publish' => 'Publish the GLO public status',
            'glo.dealers.review' => 'Review GLO dealer requests',
            'glo.salespoints.manage' => 'Manage GLO sales points',
        ],

        'Deposits' => [
            'deposits.view' => 'View the deposit queue',
            'deposits.review' => 'Review deposit evidence',
            'deposits.approve' => 'Approve a deposit',
            'deposits.reject' => 'Reject a deposit',
        ],

        'Withdrawals' => [
            'withdrawals.view' => 'View the withdrawal queue',
            'withdrawals.review' => 'Review a withdrawal request',
            'withdrawals.approve' => 'Approve a withdrawal',
            'withdrawals.reject' => 'Reject a withdrawal',
            'withdrawals.execute' => 'Execute an authorised payout',
        ],

        'Wallets & ledger' => [
            'wallets.view' => 'Inspect wallet balances',
            'wallets.adjust' => 'Request a wallet adjustment',
            'wallets.reconcile' => 'Reconcile wallet positions',
            'ledger.view' => 'Read the ledger',
            'ledger.reconcile' => 'Run a ledger reconciliation',
        ],

        'Payment providers' => [
            'payments.view' => 'View payment records',
            'payments.providers.view' => 'View provider configuration',
            'payments.providers.manage' => 'Change provider status / configuration',
        ],

        'Support' => [
            'support.view' => 'View contact messages',
            'support.manage' => 'Reply to, annotate or remove a contact message',
        ],

        'Compliance' => [
            'privacy.view' => 'View privacy / data-subject requests',
            'privacy.manage' => 'Answer a privacy request or run retention',
            'kyc.view' => 'View KYC documents',
            'kyc.review' => 'Review a KYC document',
            'kyc.approve' => 'Approve or reject KYC',
            'aml.view' => 'View AML risk assessments',
            'aml.review' => 'Investigate an AML case',
            'fraud.view' => 'View fraud alerts',
            'fraud.review' => 'Resolve a fraud alert',
        ],

        'Lottery' => [
            'lottery.games.view' => 'View games, draws and results',
            'lottery.games.manage' => 'Create or change a game / draw',
            'lottery.games.publish' => 'Publish a result or settle a draw',
        ],

        'Reporting' => [
            'reports.view' => 'Run a financial report',
            'reports.export' => 'Export a report artifact',
        ],

        'Administration' => [
            'roles.view' => 'View roles and permissions',
            'roles.manage' => 'Create roles and change permissions',
            'permissions.view' => 'View the permission catalogue',
            'settings.view' => 'View system settings',
            'settings.manage' => 'Change system settings',
            'audit_logs.view' => 'Read the audit trail',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy bridge: granular permission => coarse phrase it replaces
    |--------------------------------------------------------------------------
    |
    | Used ONLY while `granular_rbac.fallback_to_legacy` is on. Every granular
    | permission must name the coarse phrase it supersedes, so that turning the
    | fallback off is a measured decision per screen rather than a guess. A
    | granular permission with no legacy equivalent may map to null — then it is
    | granted by its own grant only.
    |
    */
    'legacy_fallback' => [
        'users.view' => 'manage users',
        'users.update' => 'manage users',
        'users.suspend' => 'manage users',
        'users.verify' => 'manage users',
        'agents.view' => 'manage agents',
        'agents.manage' => 'manage agents',
        'commissions.view' => 'view commissions',

        'bets.view' => 'view transaction history',

        // Risk limits: the number-limit screens used to ask 'view risk alerts' /
        // 'manage risk limits' directly. Same bridge, one vocabulary.
        'risk.limits.view' => 'view risk alerts',
        'risk.limits.manage' => 'manage risk limits',

        // GLO-8/11/12/14/15/16 operator duties. Each granular permission names the
        // coarse phrase it replaces, so the bridge is a per-duty decision and not a
        // wildcard over the GLO lane.
        'glo.freezes.request' => 'request glo freezes',
        'glo.freezes.review' => 'review glo freezes',
        'glo.claims.manage' => 'manage glo prize claims',
        'glo.claims.pay' => 'execute glo prize payments',
        'glo.status.publish' => 'publish glo public status',
        'glo.dealers.review' => 'review glo dealer requests',
        'glo.salespoints.manage' => 'manage glo sales points',
        // NO legacy phrase covers amending or cancelling a bet: the only betting phrase in
        // the coarse catalogue is 'place bets', which EVERY PLAYER holds. Falling back to it
        // would hand players amendment rights over other people's bets, so this permission
        // has no legacy equivalent and is granted granularly only.
        'bets.manage' => null,

        'deposits.view' => 'view transaction history',
        'deposits.review' => 'manage payouts',
        'deposits.approve' => 'manage payouts',
        'deposits.reject' => 'manage payouts',

        'withdrawals.view' => 'view transaction history',
        'withdrawals.review' => 'manage payouts',
        'withdrawals.approve' => 'manage payouts',
        'withdrawals.reject' => 'manage payouts',
        'withdrawals.execute' => 'manage payouts',

        'wallets.view' => 'manage wallet',
        'wallets.adjust' => 'manage wallet',
        'wallets.reconcile' => 'reconcile ledger',
        'ledger.view' => 'view financial reports',
        'ledger.reconcile' => 'reconcile ledger',

        'payments.providers.view' => 'manage payouts',
        'payments.providers.manage' => 'manage system settings',

        'privacy.view' => 'manage users',
        'privacy.manage' => 'manage users',
        'support.view' => 'manage users',
        'support.manage' => 'manage users',
        'payments.view' => 'manage payouts',
        'kyc.view' => 'manage users',
        'kyc.review' => 'manage users',
        'kyc.approve' => 'manage users',
        'aml.view' => 'view risk alerts',
        'aml.review' => 'view risk alerts',
        'fraud.view' => 'view risk alerts',
        'fraud.review' => 'manage risk limits',

        'lottery.games.view' => 'view draws',
        'lottery.games.manage' => 'manage draws',
        'lottery.games.publish' => 'process draws',

        'reports.view' => 'view financial reports',
        'reports.export' => 'view financial reports',

        'roles.view' => 'manage system settings',
        'roles.manage' => 'manage system settings',
        'permissions.view' => 'manage system settings',
        'settings.view' => 'manage system settings',
        'settings.manage' => 'manage system settings',
        'audit_logs.view' => 'view audit logs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Role => granular permission matrix (least privilege)
    |--------------------------------------------------------------------------
    |
    | Applied by AdminRbacSeeder with syncPermissions semantics per ROLE-LOCAL
    | list: the seeder only ever touches the granular permissions listed here,
    | so the coarse grants from RolePermissionSeeder are left untouched.
    |
    | 'super-admin' is deliberately absent: AdminAccess grants it everything
    | through the Gate bypass, so seeding rows for it would only create noise.
    |
    */
    'role_matrix' => [

        'admin' => [
            'users.view', 'users.update', 'users.suspend', 'users.verify',
            'agents.view', 'agents.manage', 'commissions.view',
            'bets.view', 'bets.manage',
            'support.view', 'support.manage',
            'privacy.view', 'privacy.manage',
            'payments.view',
            'deposits.view', 'deposits.review', 'deposits.approve', 'deposits.reject',
            'withdrawals.view', 'withdrawals.review', 'withdrawals.approve', 'withdrawals.reject',
            'wallets.view', 'wallets.adjust', 'wallets.reconcile',
            'ledger.view', 'ledger.reconcile',
            'payments.providers.view',
            'kyc.view', 'kyc.review', 'kyc.approve',
            'aml.view', 'aml.review',
            'fraud.view', 'fraud.review',
            'lottery.games.view', 'lottery.games.manage',
            'reports.view', 'reports.export',
            'roles.view', 'permissions.view',
            'settings.view',
            'audit_logs.view',
            // Risk limits and the GLO duties the 'admin' role already carried as coarse
            // phrases. The two duty-separated GLO permissions (payment execution, public
            // status publication) are deliberately NOT granted here — RolePermissionSeeder
            // withholds them from 'admin' for the same reason.
            'risk.limits.view', 'risk.limits.manage',
            'glo.freezes.request', 'glo.freezes.review',
            'glo.claims.manage', 'glo.dealers.review', 'glo.salespoints.manage',
        ],

        // Read-only auditor: may look at money, may never move it.
        'auditor' => [
            'users.view',
            'agents.view', 'commissions.view',
            'bets.view',
            'support.view',
            'privacy.view',
            'payments.view',
            'deposits.view',
            'withdrawals.view',
            'wallets.view',
            'ledger.view', 'ledger.reconcile',
            'reports.view', 'reports.export',
            'audit_logs.view',
            'lottery.games.view',
            'roles.view', 'permissions.view',
        ],

        'finance-manager' => [
            'users.view',
            'agents.view', 'commissions.view',
            'bets.view',
            'payments.view',
            'privacy.view',
            'deposits.view', 'deposits.review', 'deposits.approve', 'deposits.reject',
            'withdrawals.view', 'withdrawals.review', 'withdrawals.approve', 'withdrawals.reject', 'withdrawals.execute',
            'wallets.view', 'wallets.adjust', 'wallets.reconcile',
            'ledger.view', 'ledger.reconcile',
            'payments.providers.view',
            'reports.view', 'reports.export',
            'audit_logs.view',
        ],

        'deposit-manager' => [
            'users.view',
            'deposits.view', 'deposits.review', 'deposits.approve', 'deposits.reject',
            'payments.providers.view',
            'reports.view',
        ],

        'withdrawal-manager' => [
            'users.view',
            'withdrawals.view', 'withdrawals.review', 'withdrawals.approve', 'withdrawals.reject',
            'kyc.view',
            'payments.providers.view',
            'reports.view',
        ],

        // May read and review identity, may not move money or read AML files.
        'kyc-officer' => [
            'users.view', 'users.verify',
            'kyc.view', 'kyc.review', 'kyc.approve',
        ],

        'aml-officer' => [
            'users.view',
            'privacy.view', 'privacy.manage',
            'bets.view',
            'kyc.view',
            'aml.view', 'aml.review',
            'fraud.view',
            'reports.view', 'reports.export',
            'audit_logs.view',
        ],

        'fraud-analyst' => [
            'users.view',
            'fraud.view', 'fraud.review',
            'aml.view',
            'deposits.view',
            'withdrawals.view',
            'reports.view',
        ],

        'support-agent' => [
            'users.view',
            'agents.view',
            'bets.view',
            'support.view', 'support.manage',
            'kyc.view',
            'deposits.view',
            'withdrawals.view',
            'wallets.view',
        ],

        'lottery-manager' => [
            'bets.view',
            'lottery.games.view', 'lottery.games.manage', 'lottery.games.publish',
            'reports.view',
            'audit_logs.view',
        ],

        'read-only-analyst' => [
            'users.view',
            'agents.view', 'commissions.view',
            'bets.view',
            'payments.view',
            'support.view',
            'privacy.view',
            'deposits.view',
            'withdrawals.view',
            'wallets.view',
            'ledger.view',
            'reports.view',
            'lottery.games.view',
            'audit_logs.view',
            // May READ the RBAC map (who can do what is an audit question) but holds no
            // roles.manage, so the role screen is a reference for them, not a tool.
            'roles.view',
            'permissions.view',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Policy ability map — the dot vocabulary the Laravel policies ask about
    |--------------------------------------------------------------------------
    |
    | THE PROBLEM THIS SOLVES
    |
    | App\Policies\BasePolicy built abilities as "<prefix>.<method>" — 'wallet.viewAny',
    | 'draw.close', 'contact-message.reply' — and asked `$user->can('wallet.viewAny')`.
    | No seeder has ever created a permission with a dot in it: the seeded catalogue is
    | phrases with spaces. The result was that every one of those 15 policies returned
    | false for every operator who was not a super-admin (the Gate::before bypass was the
    | only thing that ever passed), so the API's policy layer was effectively
    | super-admin-only. It failed closed, which is why it was not a vulnerability — but it
    | was not authorisation either.
    |
    | THE FIX
    |
    | Each ability is now mapped to the granular permission that owns the decision, and
    | BasePolicy asks AdminAccess (one decision point) instead of `$user->can()`. An
    | existing deployment that DID seed dot permissions keeps working: a direct grant of
    | the ability itself is still honoured first.
    |
    | 'never' means "no operator may do this through a policy". Those are the writes that
    | belong to a domain service and must not have a policy shortcut: creating a ledger
    | entry by hand, deleting a draw, deleting a wallet, hard-deleting a privacy request.
    | Mapping them to a permission would let an operator reach past the ledger's own rules.
    |
    | COMPLETENESS IS TESTED, NOT ASSUMED
    |
    | tests/Feature/Admin/PolicyAbilityMapTest.php walks every prefixed policy method and
    | fails if one is missing from this map, so adding a policy method without deciding who
    | may use it is a failing test rather than a silent deny-forever.
    |
    */
    'policy_abilities' => [

        // Wallets — reading is an inspection power, writing is an adjustment power,
        // deletion is nobody's (wallets are closed, never removed).
        'wallet.viewAny' => 'wallets.view',
        'wallet.view' => 'wallets.view',
        'wallet.create' => 'wallets.adjust',
        'wallet.update' => 'wallets.adjust',
        'wallet.delete' => 'never',

        // Agents and commissions.
        'agent.viewAny' => 'agents.view',
        'agent.view' => 'agents.view',
        'agent.create' => 'agents.manage',
        'agent.update' => 'agents.manage',
        'agent.delete' => 'agents.manage',
        'commission.viewAny' => 'commissions.view',
        'commission.view' => 'commissions.view',

        // Bets and tickets. 'bet.amend' and 'bet.cancel' are separate domain powers but
        // both sit behind the same "may change a recorded bet" grant, because the audit
        // difference is captured by the service that performs them, not by the gate.
        'bet.viewAny' => 'bets.view',
        'bet.view' => 'bets.view',
        'bet.create' => 'bets.manage',
        'bet.update' => 'bets.manage',
        'bet.delete' => 'bets.manage',
        'bet.amend' => 'bets.manage',
        'bet.cancel' => 'bets.manage',
        'ticket.viewAny' => 'bets.view',
        'ticket.view' => 'bets.view',
        'ticket.create' => 'bets.manage',
        'ticket.update' => 'bets.manage',
        'ticket.delete' => 'bets.manage',
        'ticket.share' => 'bets.manage',
        'ticket.revoke' => 'bets.manage',

        // Draws. Closing and cancelling settle money, so they need the publishing grant,
        // not the editing one. Deleting a draw would erase settled history: never.
        'draw.viewAny' => 'lottery.games.view',
        'draw.view' => 'lottery.games.view',
        'draw.create' => 'lottery.games.manage',
        'draw.update' => 'lottery.games.manage',
        'draw.close' => 'lottery.games.publish',
        'draw.cancel' => 'lottery.games.publish',
        'draw.delete' => 'never',

        // Ledger and chart of accounts: readable, never hand-written.
        'ledger.viewAny' => 'ledger.view',
        'ledger.view' => 'ledger.view',
        'ledger.create' => 'never',
        'ledger.update' => 'never',
        'ledger.delete' => 'never',
        'ledger_account.viewAny' => 'ledger.view',
        'ledger_account.view' => 'ledger.view',
        'ledger_account.create' => 'never',
        'ledger_account.update' => 'never',
        'ledger_account.delete' => 'never',

        // Payments: records are produced by the payment lanes, never edited.
        'payment.viewAny' => 'payments.view',
        'payment.view' => 'payments.view',
        'payment.create' => 'never',
        'payment.update' => 'never',
        'payment.delete' => 'never',

        // Support inbox.
        'contact-message.viewAny' => 'support.view',
        'contact-message.view' => 'support.view',
        'contact-message.create' => 'support.manage',
        'contact-message.update' => 'support.manage',
        'contact-message.reply' => 'support.manage',
        'contact-message.note' => 'support.manage',
        'contact-message.delete' => 'support.manage',
        'contact-message.deleteAny' => 'never',
        'contact-message.restore' => 'never',
        'contact-message.forceDelete' => 'never',

        // Privacy / data-subject requests. Retention evaluation can destroy data, so it
        // needs the manage grant; a hard delete is nobody's.
        'privacy-request.viewAny' => 'privacy.view',
        'privacy-request.view' => 'privacy.view',
        'privacy-request.create' => 'privacy.manage',
        'privacy-request.update' => 'privacy.manage',
        'privacy-request.export' => 'privacy.manage',
        'privacy-request.evaluateRetention' => 'privacy.manage',
        'privacy-request.delete' => 'never',
        'privacy-request.deleteAny' => 'never',
        'privacy-request.restore' => 'never',
        'privacy-request.forceDelete' => 'never',
    ],

    /*
    |--------------------------------------------------------------------------
    | Report desk
    |--------------------------------------------------------------------------
    |
    | Guard-rails for the administrative report surface. The projection itself
    | lives in App\Services\Operations\OperationalReportService; these are only
    | the limits the panel refuses to exceed before calling it.
    |
    */
    'reports' => [
        // Longest horizon (days) an operator may ask for in one request.
        'max_horizon_days' => (int) env('ADMIN_REPORT_MAX_HORIZON_DAYS', 366),

        // Default horizon when the operator does not pick dates.
        'default_horizon_days' => (int) env('ADMIN_REPORT_DEFAULT_HORIZON_DAYS', 30),

        // Formats the desk offers. Anything else is refused before the export lane.
        'formats' => ['csv', 'json'],
    ],

];
