<?php

use App\Enums\AuditAction;

/*
|--------------------------------------------------------------------------
| Application Security Configuration
|--------------------------------------------------------------------------
|
| Configuration only. No rate limiter class and no additional middleware are
| created in this phase; App\Http\Middleware\SecurityHeaders is the single
| existing consumer and it reads the 'security_headers' section below.
|
| SCOPE
| These settings harden application-level behaviour: throttling, auditing,
| idempotency and response headers. They do not provide network-layer or
| volumetric DDoS protection, which must be handled by a CDN, reverse proxy or
| firewall in front of the application.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Named limiters are registered from these values in a later phase. Keys are
    | kept flat and explicit so a limiter definition maps to exactly one entry.
    |
    */

    'rate_limits' => [

        'login' => [
            'max_attempts' => (int) env('RATE_LIMIT_LOGIN_ATTEMPTS', 5),
            'decay_minutes' => (int) env('RATE_LIMIT_LOGIN_DECAY_MINUTES', 15),
            'by' => 'email_and_ip',
        ],

        'api' => [
            'max_per_minute' => (int) env('RATE_LIMIT_API_PER_MINUTE', 60),
            'decay_seconds' => 60,
            'by' => 'user_or_ip',
        ],

        'bet' => [
            'max_per_minute' => (int) env('RATE_LIMIT_BET_PER_MINUTE', 10),
            'decay_seconds' => 60,
            'by' => 'user',
        ],

        'deposit' => [
            'max_per_hour' => (int) env('RATE_LIMIT_DEPOSIT_PER_HOUR', 5),
            'decay_seconds' => 3600,
            'by' => 'user',
        ],

        'withdrawal' => [
            'max_per_day' => (int) env('RATE_LIMIT_WITHDRAWAL_PER_DAY', 3),
            'decay_seconds' => 86400,
            'by' => 'user',
        ],

        /*
        |----------------------------------------------------------------------
        | Money-moving writes with no ceiling of their own
        |----------------------------------------------------------------------
        |
        | 'financial_critical' is the per-user ceiling on a money-moving write that
        | has no tighter limiter attached - cancelling a bet (which refunds the
        | stake), cancelling a withdrawal request, or petitioning a payout. The
        | create surfaces are not in its scope: 'deposit' and 'withdrawal' above
        | are tighter. Read by registerRateLimiters(), where the fallback is the
        | same 30 so a missing key cannot widen the guard. A non-positive value is
        | deliberately not clamped: measured against the framework, a ceiling of 0
        | or less already refuses from the second request onward, which is the same
        | answer a clamp would give at the cost of a second rule.
        |
        */

        'financial_critical' => [
            'max_per_minute' => 30,
            'decay_seconds' => 60,
            'by' => 'user',
        ],

        /*
        |----------------------------------------------------------------------
        | Public ticket check (Home page)
        |----------------------------------------------------------------------
        |
        | Anonymous, so IP-keyed: there is no user to key on. Same shape as
        | 'financial_critical' - this number used to be hard-coded in the provider.
        |
        */

        'home_check' => [
            'max_per_minute' => 10,
            'decay_seconds' => 60,
            'by' => 'ip',
        ],

        'webhook' => [
            'max_per_minute' => 120,
            'decay_seconds' => 60,
            'by' => 'ip',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Financial Operation Protection
    |--------------------------------------------------------------------------
    |
    | Extra requirements applied to any request that can move money, on top of
    | the ordinary rate limits above.
    |
    */

    'financial_operations' => [
        'require_authentication' => true,
        'require_active_user' => true,
        'require_active_wallet' => true,
        'require_idempotency_key' => true,
        'require_https' => true,
        'operations' => [
            'deposit',
            'withdrawal',
            'bet',
            'payout',
            'transfer',
            'commission_settlement',
        ],
        'log_every_attempt' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Protection
    |--------------------------------------------------------------------------
    */

    'authentication' => [
        'lockout_enabled' => true,
        'max_failed_attempts' => (int) env('RATE_LIMIT_LOGIN_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('RATE_LIMIT_LOGIN_DECAY_MINUTES', 15),
        'password_min_length' => 10,
        'require_mixed_case' => true,
        'require_number' => true,
        'require_symbol' => false,
        'log_failed_attempts' => true,
        'logout_other_devices_on_password_change' => true,
        'verify_email' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    |
    | audit_logs is append only: rows are never updated or soft deleted, so the
    | trail cannot be rewritten after the fact.
    |
    */

    'audit' => [
        'enabled' => (bool) env('AUDIT_LOG_ENABLED', true),
        'append_only' => true,
        'retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 365),
        'actions' => array_column(AuditAction::cases(), 'value'),
        'log_ip_address' => true,
        'log_user_agent' => true,
        'log_request_url' => true,
        'sensitive_fields' => [
            'password',
            'password_confirmation',
            'current_password',
            'secret',
            'api_key',
            'api_secret',
            'private_key',
            'token',
            'access_token',
            'refresh_token',
            'webhook_secret',
            'payout_details',
            'card_number',
            'card_no',
            'cvv',
            'pin',
            'bank_account',
        ],
        'redaction_placeholder' => '[REDACTED]',
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    */

    'idempotency' => [
        'enabled' => true,
        'header' => env('IDEMPOTENCY_KEY_HEADER', 'X-Idempotency-Key'),
        'cache_ttl' => (int) env('IDEMPOTENCY_CACHE_TTL', 86400),
        'cache_prefix' => 'security:idempotency:',
        'min_key_length' => 16,
        'max_key_length' => 128,
    ],

    /*
    |--------------------------------------------------------------------------
    | Suspicious Activity Detection
    |--------------------------------------------------------------------------
    |
    | Signals are recorded for review. Automatic blocking of betting activity is
    | governed by config/risk.php, not by this section.
    |
    */

    'suspicious_activity' => [
        'enabled' => true,
        'signals' => [
            'repeated_failed_logins',
            'login_from_new_country',
            'rapid_deposit_withdrawal_cycle',
            'multiple_accounts_same_device',
            'stake_spike_before_draw_close',
            'withdrawal_details_changed_recently',
        ],
        'failed_login_window_minutes' => 15,
        'deposit_withdrawal_cycle_minutes' => 30,
        'withdrawal_detail_change_hold_hours' => 24,
        'notify_admin' => true,
        'record_audit_entry' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Security
    |--------------------------------------------------------------------------
    |
    | Per-gateway secrets and signature headers are declared in
    | config/payment.php and validated by VerifyWebhookSignature.
    |
    */

    'webhook' => [
        'verify_signature' => true,
        'algorithm' => 'sha256',
        'timestamp_tolerance_seconds' => 300,
        'reject_missing_signature' => true,
        'replay_protection' => true,
        'allowed_ips' => [],
        'log_rejections' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Headers
    |--------------------------------------------------------------------------
    |
    | Keys here are mapped to real HTTP headers by SecurityHeaders middleware.
    | HSTS is only emitted on secure requests.
    |
    */

    'security_headers' => [
        'x_frame_options' => 'DENY',
        'x_content_type_options' => 'nosniff',
        'x_xss_protection' => '1; mode=block',
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'permissions_policy' => 'geolocation=(), microphone=(), camera=()',
        'hsts_max_age' => 31536000,
        'hsts_include_subdomains' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Emitted by the same SecurityHeaders middleware as the headers above, from
    | the directives below. Each directive is a list of source expressions and is
    | joined with a space, so the ordering here is the ordering on the wire.
    |
    | Every non-'self' source in this list exists because a view in this
    | repository actually loads it. AssetReferenceIntegrityTest and
    | ContentSecurityPolicyTest re-derive that inventory from the view tree and
    | fail if the two lists drift apart in either direction: a new CDN in a
    | template without a matching directive breaks the page in production, and a
    | directive left behind after the CDN was removed silently widens the policy.
    |
    | script-src is 'self' and nothing else: no third-party JavaScript runs on any page
    | here. That is only true because the Tailwind Play CDN - a runtime CSS compiler
    | that three standalone documents depended on for their utility classes - was
    | replaced by a local build of the same utilities.
    |
    | 'unsafe-inline' appears for style-src and for nothing else, and that is a
    | deliberate, documented trade rather than an oversight:
    |
    |   - 73 inline style="..." attributes and 4 inline <style> blocks exist in
    |     the view tree, and nonce-ing every one of them is a different project;
    |   - the pages that kept inline <style> blocks did so before this policy existed,
    |     and moving every one of them into a bundled stylesheet is a separate job.
    |
    | script-src carries NO 'unsafe-inline' and no 'unsafe-eval'. That is only
    | true because the five onclick="return confirm(...)" handlers that used to
    | sit in the player and admin templates were moved into
    | resources/js/confirm-guard.js, which reads a data-confirm attribute. Adding
    | an inline handler back would be blocked by the browser, and
    | ContentSecurityPolicyTest fails the build before it ever ships.
    |
    */

    'content_security_policy' => [
        'enabled' => env('CSP_ENABLED', true),

        // Report-Only sends the header as Content-Security-Policy-Report-Only, so a
        // browser evaluates and reports violations without enforcing them. Useful for
        // a first rollout against real traffic; leave false once the policy is known
        // good, because a report-only policy protects nothing.
        'report_only' => env('CSP_REPORT_ONLY', false),

        // Optional violation endpoint. Null omits both report-uri and report-to.
        'report_uri' => env('CSP_REPORT_URI'),

        // Appended as the bare 'upgrade-insecure-requests' directive. Forced on in
        // production regardless of this flag, so an http subresource on an https page
        // cannot be mixed in by accident.
        'upgrade_insecure_requests' => env('CSP_UPGRADE_INSECURE_REQUESTS', false),

        'directives' => [
            // Anything not named by a more specific directive falls back to this.
            'default-src' => ["'self'"],

            // Scripts: the built bundles, and nothing else. No third-party script runs
            // on any page in this application.
            //
            // This list used to carry https://cdn.tailwindcss.com for three standalone
            // documents whose own stylesheets (thailotto-theme.css, admin-lottofin.css)
            // contain no @tailwind directives. Those pages now build their utilities
            // locally through resources/css/standalone-tailwind.css and
            // resources/css/admin-tailwind.css, so the runtime compiler - and with it the
            // last non-'self' script source - is gone.
            'script-src' => ["'self'"],

            // Stylesheets. 'unsafe-inline' is required by the inline style attributes
            // and <style> blocks listed above, and by the Play CDN's runtime output.
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com', 'https://cdnjs.cloudflare.com'],

            // Font files. Google Fonts serves its CSS from fonts.googleapis.com but the
            // actual woff2 files from fonts.gstatic.com - allowlisting only the first
            // would give a page whose text renders in a fallback face. Font Awesome
            // serves its own fonts from cdnjs.cloudflare.com.
            'font-src' => ["'self'", 'data:', 'https://fonts.gstatic.com', 'https://cdnjs.cloudflare.com'],

            // Images are same-origin, inline data URIs, or object URLs created by the
            // application itself. This list used to carry https://images.unsplash.com for
            // a stock photograph the admin console showed as every operator's avatar; the
            // console now renders the operator's own initials, so the origin was orphaned
            // and ContentSecurityPolicyTest's drift check required its removal.
            'img-src' => ["'self'", 'data:', 'blob:'],

            // Every fetch, XHR and EventSource in this application is same-origin: the
            // chat widget polls relative URLs and no module calls an external API.
            'connect-src' => ["'self'"],

            'media-src' => ["'self'"],
            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],

            // No view in this repository embeds an iframe - the single <iframe> match
            // in the tree is the word appearing inside a comment in home.blade.php.
            'frame-src' => ["'none'"],
            'object-src' => ["'none'"],

            // Nothing may be loaded as a document inside a frame. This is the modern
            // replacement for the X-Frame-Options: DENY emitted above, and it also
            // covers the cases X-Frame-Options cannot express.
            'frame-ancestors' => ["'none'"],

            // A form may only post back to this origin, which closes the trick of
            // injecting a form that submits credentials somewhere else.
            'form-action' => ["'self'"],
            'base-uri' => ["'self'"],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Security
    |--------------------------------------------------------------------------
    |
    | These are the security expectations for a production deployment. The
    | effective runtime values are read by Laravel from config/session.php, so
    | this section documents and validates intent rather than replacing it.
    |
    */

    'session' => [
        'expected_driver' => 'database',
        'lifetime_minutes' => (int) env('SESSION_LIFETIME', 120),
        'require_encryption_in_production' => true,
        'require_secure_cookie_in_production' => true,
        'require_http_only_cookie' => true,
        'same_site' => 'lax',
        'regenerate_on_login' => true,
        'invalidate_on_logout' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache store
    |--------------------------------------------------------------------------
    |
    | Enforced by ProductionSafetyServiceProvider alongside the session checks,
    | for the same reason those exist: a file cache is atomic on ONE machine, so
    | a cache-backed claim is a different answer on every container behind a load
    | balancer and a meaningless one across a restart.
    |
    | The claims that matter are cross-instance ones — idempotency keys, the
    | throttle buckets behind the 90 `throttle:*` bindings (including
    | `throttle:webhook` on the public money-ingest surface), and any remaining
    | replay check. A genuine single-instance deployment can set this to false,
    | and the change should say so out loud, because on a file cache every deploy
    | also clears every claim that was in it.
    |
    | The durable webhook_replay_guards table means the inbound payment webhook
    | replay control no longer relies on the cache for its guarantee. It is the
    | other claims that keep this check necessary.
    |
    */

    'cache' => [
        'require_shared_store_in_production' => true,
    ],

];
