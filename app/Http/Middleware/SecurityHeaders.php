<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Security\SecurityHeaderPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the response security headers.
 *
 * THE POLICY LIVES IN {@see SecurityHeaderPolicy}. This class is now only the
 * request/response adapter: it asks the policy what the headers are, sets them,
 * and preserves two things that must never be overwritten —
 *
 *   1. a stricter Content-Security-Policy already set by a private download
 *      controller (`sandbox; default-src 'none'`), which is deliberately harsher
 *      than the site policy and must not be downgraded by the middleware; and
 *   2. a `Vary`-sensitive cache, by leaving the response's own caching headers
 *      untouched.
 *
 * THE NONCE ORDER MATTERS. `Vite::useCspNonce()` is called BEFORE the pipeline
 * continues so `@cspNonce` in Blade and the header share one request-scoped
 * nonce. Calling it afterwards would emit a header that no inline script could
 * ever match, which breaks every page with an inline bootstrap block.
 */
final class SecurityHeaders
{
    public function __construct(private readonly SecurityHeaderPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->policy->cspEnabled()) {
            Vite::useCspNonce();
        }

        $response = $next($request);

        foreach ($this->policy->headers() as $header => $value) {
            $response->headers->set($header, $value);
        }

        if ($this->policy->isPrivate($request)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        $hsts = $this->policy->strictTransportSecurity($request);

        if ($hsts !== '') {
            $response->headers->set('Strict-Transport-Security', $hsts);
        }

        $existingCsp = trim((string) $response->headers->get('Content-Security-Policy', ''));

        if ($this->policy->cspEnabled() && $existingCsp === '') {
            $nonce = Vite::cspNonce();
            $csp = $this->policy->csp(is_string($nonce) ? $nonce : null);

            if ($csp !== '') {
                $response->headers->set($this->policy->cspHeaderName(), $csp);
            }
        }

        return $response;
    }
}
