<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as BaseVerifyCsrfToken;

/**
 * CSRF Verification Middleware with Strict Exemption Allowlisting.
 *
 * Exemption is allowed ONLY for webhook endpoints protected by cryptographic
 * provider signature verification. All browser financial mutations require CSRF tokens.
 */
class VerifyCsrfToken extends BaseVerifyCsrfToken
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/*',
        'api/v1/payments/webhook/*',
        'api/payment/webhooks/*',
    ];
}
