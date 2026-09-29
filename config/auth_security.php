<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authentication Security (PROMPT 3 — canonical auth-security config)
|--------------------------------------------------------------------------
|
| ONE place for the member-auth security policy: CAPTCHA enablement,
| login throttling, session policy, password-reset expiry, identifier
| rules, the generic-auth-error policy and account-lock thresholds.
|
| DELIBERATELY ALIGNED with config/security.php: the login throttle
| numbers read the SAME env keys (RATE_LIMIT_LOGIN_ATTEMPTS /
| RATE_LIMIT_LOGIN_DECAY_MINUTES) so the two files can never disagree
| about how many attempts a member gets. auth_security.php owns the
| auth-surface policy; security.php owns the platform-wide numbers.
|
| Nothing in here is a business rule. Nothing here touches money,
| grades, fees or the discount matrix.
|
*/

return [
    /*
    |----------------------------------------------------------------------
    | Rule version — bump when the auth policy contract changes.
    |----------------------------------------------------------------------
    */
    'rule_version' => (string) env('AUTH_SECURITY_RULE_VERSION', '1'),

    /*
    |----------------------------------------------------------------------
    | CAPTCHA
    |----------------------------------------------------------------------
    | Server-authoritative challenge/verification for the anonymous
    | member surfaces (login, password recovery). The driver is
    | configurable; 'local' renders a server-generated arithmetic
    | challenge and stores only a HASH of the answer in the session.
    |
    | enabled=false is an operational switch (e.g. a staging tier behind
    | an SSO gate), never a client-controlled flag: the answer is ALWAYS
    | verified server-side when enabled, and 'captcha=true' from a
    | client is never accepted as proof.
    |----------------------------------------------------------------------
    */
    'captcha' => [
        'enabled' => (bool) env('AUTH_CAPTCHA_ENABLED', true),
        'driver' => (string) env('AUTH_CAPTCHA_DRIVER', 'local'),
        // Seconds a challenge stays verifiable after issue.
        'ttl_seconds' => (int) env('AUTH_CAPTCHA_TTL_SECONDS', 600),
        // Failed verifications per session before the challenge is
        // force-refreshed and a short cool-down applies.
        'max_failures' => (int) env('AUTH_CAPTCHA_MAX_FAILURES', 5),
        'cooldown_seconds' => (int) env('AUTH_CAPTCHA_COOLDOWN_SECONDS', 60),
        // Surfaces that carry a CAPTCHA gate.
        'login' => (bool) env('AUTH_CAPTCHA_LOGIN', true),
        'password_reset' => (bool) env('AUTH_CAPTCHA_PASSWORD_RESET', true),
    ],

    /*
    |----------------------------------------------------------------------
    | Login throttling (dimensions + thresholds)
    |----------------------------------------------------------------------
    | The limiter itself lives in AppServiceProvider ('login') and is
    | applied by the throttle:login middleware. Dimensions: normalized
    | identifier (hashed) AND client IP, so no single dimension can be
    | used to enumerate accounts or to deny service cross-user.
    |----------------------------------------------------------------------
    */
    'throttling' => [
        'max_attempts' => (int) env('RATE_LIMIT_LOGIN_ATTEMPTS', 5),
        'decay_minutes' => (int) env('RATE_LIMIT_LOGIN_DECAY_MINUTES', 15),
        // Whether a successful authentication clears the identifier's
        // failure window (project policy: yes — a legitimate user who
        // mistypes once must not carry the failures forward).
        'clear_on_success' => (bool) env('AUTH_LOGIN_CLEAR_ON_SUCCESS', true),
    ],

    /*
    |----------------------------------------------------------------------
    | Session policy
    |----------------------------------------------------------------------
    */
    'session' => [
        // Always regenerate on login (framework does this via the
        // service; this flag documents and asserts the contract).
        'regenerate_on_login' => true,
        // Preserve ONLY these keys across the login regeneration.
        'preserve_keys' => ['url.intended', '_token'],
    ],

    /*
    |----------------------------------------------------------------------
    | Password reset
    |----------------------------------------------------------------------
    | The token itself is generated, HASHED at rest, expiring and
    | single-use by the framework broker (config/auth.php). This block
    | governs the recovery-surface policy around it.
    |----------------------------------------------------------------------
    */
    'password_reset' => [
        'expiry_minutes' => (int) env('AUTH_PASSWORD_RESET_EXPIRY_MINUTES', 60),
        // Requests per identifier+IP per minute for the recovery form.
        'max_requests_per_minute' => (int) env('AUTH_PASSWORD_RESET_MAX_PER_MINUTE', 5),
        // Revoke database sessions + remember-device tokens of the user
        // after a successful reset.
        'revoke_sessions' => (bool) env('AUTH_PASSWORD_RESET_REVOKE_SESSIONS', true),
    ],

    /*
    |----------------------------------------------------------------------
    | Identifier rules
    |----------------------------------------------------------------------
    | "Account ID or Email" resolution order: a digits-only string is
    | treated as the numeric account id, a valid email shape as email,
    | everything else as username (the pre-existing login dimension).
    |----------------------------------------------------------------------
    */
    'identifiers' => [
        'account_id_pattern' => '/^\d{1,15}$/',
        'email_max_length' => 255,
        'username_min_length' => 3,
        'username_max_length' => 30,
    ],

    /*
    |----------------------------------------------------------------------
    | Password policy (shared by registration and password reset).
    |----------------------------------------------------------------------
    | Matches the platform's existing contract: minimum 8 characters
    | containing at least one letter and one number. Complexity beyond
    | that stays configurable so legitimate existing passwords are
    | never locked out.
    |----------------------------------------------------------------------
    */
    'passwords' => [
        'min_length' => (int) env('AUTH_PASSWORD_MIN_LENGTH', 8),
    ],

    /*
    |----------------------------------------------------------------------
    | Generic failure policy (anti-enumeration)
    |----------------------------------------------------------------------
    | One outward message for every failed login reason (unknown
    | identifier, wrong password, malformed input after validation).
    | Status-specific messages are allowed ONLY after successful
    | credential verification (the authenticated user knows their own
    | status; an attacker without credentials learns nothing).
    |----------------------------------------------------------------------
    */
    'messages' => [
        'generic_failure' => (string) env('AUTH_GENERIC_FAILURE_MESSAGE', 'These credentials are invalid.'),
        'throttled' => 'Too many attempts. Please try again later.',
        'captcha_failed' => 'The security check failed. Please try again.',
    ],

    /*
    |----------------------------------------------------------------------
    | Account lock observation threshold
    |----------------------------------------------------------------------
    | Lockouts are NOT invented here — the account-status semantics
    | (active / suspended / banned / …) stay exactly as the domain
    | defines them. This threshold only feeds the existing security
    | audit recorder / suspicious-authentication telemetry when a
    | sustained failure streak is observed against one identifier.
    |----------------------------------------------------------------------
    */
    'lock' => [
        'observation_threshold' => (int) env('AUTH_LOCK_OBSERVATION_THRESHOLD', 10),
    ],
];
