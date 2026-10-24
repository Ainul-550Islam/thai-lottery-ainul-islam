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
     * THE CONSTANT IS THE CONTRACT.
     * `bootstrap/app.php` reads `VerifyCsrfToken::WEBHOOK_EXCEPTIONS` when it
     * registers the middleware, and the security regression suite asserts on the
     * same array. Without the constant the framework threw
     * "Undefined constant ...::WEBHOOK_EXCEPTIONS" while booting
     * bootstrap/app.php, so every artisan command and every HTTP request failed
     * before a single route was matched.
     *
     * @var list<string>
     */
    public const WEBHOOK_EXCEPTIONS = [
        'api/v1/payments/webhook/*',
        'api/v1/stripe/deposit-reviews/webhook',
        'api/payment/webhooks/*',
    ];

    /**
     * Laravel reads this property; it is derived from the constant so the two can
     * never disagree, and no exemption can exist in one place but not the other.
     *
     * @var array<int, string>
     */
    protected $except = self::WEBHOOK_EXCEPTIONS;
}
