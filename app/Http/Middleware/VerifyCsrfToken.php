<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as BaseVerifyCsrfToken;

/**
 * CSRF Verification Middleware with Strict Exemption Allowlisting.
 *
 * Exemption is allowed ONLY for server-to-server webhook endpoints protected by
 * cryptographic provider signature verification and durable replay guards.
 * Broad wildcard exemptions such as `api/*` are strictly forbidden so no
 * session-authenticated state-changing route can bypass CSRF verification.
 */
final class VerifyCsrfToken extends BaseVerifyCsrfToken
{
    /**
     * Narrow, justified URI allowlist for signed external provider webhooks only.
     *
     * Declared as a PUBLIC constant, not a literal array, because
     * bootstrap/app.php registers the CSRF exemptions globally through
     * `$middleware->validateCsrfTokens(except: ...)` while the base class keeps
     * `$except` protected. Without a public accessor the bootstrap file cannot
     * read the list, and the two declarations silently drift apart — which is
     * exactly the state this repository was left in (bootstrap referenced this
     * constant, the constant did not exist, and the application failed to boot
     * with "Undefined constant ...::WEBHOOK_EXCEPTIONS").
     *
     * `tests/Security/SecurityNextHardeningVerificationTest` asserts against
     * this constant, so it is the single source of truth for both the runtime
     * middleware and the test suite.
     *
     * @var array<int, string>
     */
    public const WEBHOOK_EXCEPTIONS = [
        'api/v1/payments/webhook/*',
        'api/v1/stripe/deposit-reviews/webhook',
        'api/payment/webhooks/*',
    ];

    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = self::WEBHOOK_EXCEPTIONS;
}
