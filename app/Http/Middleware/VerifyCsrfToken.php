<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as BaseVerifyCsrfToken;

/**
 * CSRF verification for the browser-authenticated `web` group, plus the one exemption
 * list the platform actually needs: the two signed payment-gateway webhook endpoints.
 *
 * WHY THIS CLASS HAS TO BE REGISTERED, NOT JUST DECLARED
 * Illuminate\Foundation\Http\Middleware\ValidateCsrfToken - which is what the web group
 * carried - is the same middleware with no exemptions of its own. So while this subclass
 * existed only as a file, the exemption list below had no enforcement path and the
 * sentence "exemption is allowed only for webhook endpoints" described nothing.
 * bootstrap/app.php now replaces the framework entry with this class, which turns the
 * list into a policy. An exemption is permitted only for a route that verifies a
 * provider signature; that rule is asserted against the live route table by
 * tests/Feature/Security/MiddlewareGuardReachabilityTest.
 *
 * WHY `api/*` IS NOT IN THE LIST
 * The api group carries no CSRF middleware at all (its only member is
 * Illuminate\Routing\Middleware\SubstituteBindings), so an api-wide exemption would be inert
 * today and would silently switch off CSRF for every money endpoint the day the api group
 * gains a session, which is what Sanctum's stateful frontend mode does. The list used to
 * also name api/payment/webhooks/*, a path matching none of the 585 registered routes; the
 * real endpoints are api/v1/payments/webhook/{gateway} and its /v2 sibling, and both carry
 * App\Http\Middleware\VerifyWebhookSignature.
 */
class VerifyCsrfToken extends BaseVerifyCsrfToken
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/v1/payments/webhook/*',
        'api/v1/payments/webhook/v2/*',
    ];
}
