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
     * @var array<int, string>
     */
    protected $except = [
        'api/v1/payments/webhook/*',
        'api/v1/stripe/deposit-reviews/webhook',
        'api/payment/webhooks/*',
    ];
}
