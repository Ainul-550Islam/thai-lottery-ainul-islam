<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Chat\ChatIdentityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PublicLegalHeaders — cache policy for public legal/informational pages.
 *
 * Which paths this applies to is decided HERE, not by the alias attachment: see
 * CACHEABLE_PATHS. The `public.legal` alias is deliberately attached to every
 * guest-facing public route in routes/web.php, so the great majority of those
 * attachments reach the early return below and change nothing. That breadth is
 * the point: a newly added page inherits the policy, and this class remains the
 * only place that grants a shared-cache window. MiddlewareGuardReachabilityTest
 * pins both halves (allow-listed paths are attached, non-allow-listed paths stay
 * untouched) so neither side can drift silently.
 *
 * A guest GET of an allow-listed page gets a short PUBLIC window so CDNs and
 * browsers may reuse the HTML, with Vary on Cookie/Accept-Language. Four things
 * withhold that window, each for a different reason:
 *  - the path is not allow-listed (returned untouched, so no header is asserted
 *    about a page this class knows nothing about);
 *  - the request is not GET/HEAD, or the visitor is authenticated — sessions
 *    must not be stored by shared caches, and nothing here ever asks a cache to
 *    store under a private key;
 *  - the response mints a chat identity cookie for this visitor (see below);
 *  - the response is not 2xx (see below).
 *
 * Does not weaken SecurityHeaders (X-Frame-Options etc.) registered globally
 * in bootstrap/app.php. No PII, secrets, or admin metrics are added here.
 */
final class PublicLegalHeaders
{
    // PROMPT 5 adds 'national-lottery' (the landing page only). The match
    // below is EXACT, so '/national-lottery/search' is not in this list and
    // therefore never receives a shared-cache header - a query-dependent
    // response must not be storable by a proxy. Draw and year pages are also
    // excluded: their content changes when a correction is published, and the
    // application-level cache (keyed on the result version) is the correct
    // place for that, not an edge cache this code cannot purge.
    //
    // '/privacy' is intentionally absent even though it is as static as
    // '/terms' (both render config + lang files only): caching it would be a
    // product decision about which pages the edge may hold, not a bug fix, so
    // it is left to whoever owns that list. '/fees' is absent for the reason
    // above - PublicLegalFeesController reads live figures through
    // FeesPageService, and money-adjacent numbers belong in the purgeable
    // application cache.
    public const CACHEABLE_PATHS = ['about', 'vision', 'terms', 'national-lottery', 'weekly-lottery'];

    public const PUBLIC_MAX_AGE_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $path = trim($request->path(), '/');
        if (! in_array($path, self::CACHEABLE_PATHS, true)) {
            return $response;
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $this->forbidStoring($response);
        }

        // Logged-in players must never be stored by shared caches.
        // Guest GETs (even with a session cookie from StartSession) may be
        // publicly cached with Vary: Cookie — the response carries no PII.
        if ($request->user() !== null) {
            return $this->forbidStoring($response);
        }

        // EnsureChatGuestToken mints a fresh visitor identity pre-controller and
        // hands it over through this request attribute so the controller can set
        // the cookie. A response that *creates* the credential a later request
        // will present must not be reusable: a shared cache would either drop
        // the Set-Cookie (nobody gets an identity, so the chat lane stays shut)
        // or replay one visitor's token to the next, and VerifyChatOrigin trusts
        // that cookie as the identity of whoever presents it. The first-visit
        // case is the exposed one - a brand-new visitor sends no Cookie header
        // at all, so "Vary: Cookie" cannot fragment anything between visitors.
        if ($request->attributes->has(ChatIdentityService::REQUEST_ATTR_NEW_TOKEN)) {
            return $this->forbidStoring($response);
        }

        // Only a response that answered as designed may be advertised as
        // reusable. Without this gate a 500 from a database blip, or a 429 from
        // the throttle, carries s-maxage=60 into every cache in front of the app,
        // and one transient failure becomes a minute of stored outage served to
        // everyone — this class cannot purge an edge cache to undo it. Redirects
        // are left alone on purpose: they are the app canonicalising its own URLs,
        // and withholding the window there would cost a round trip per first visit.
        if ($response->isClientError() || $response->isServerError()) {
            return $this->forbidStoring($response);
        }

        $maxAge = max(0, (int) config('public_pages.legal_headers_max_age', self::PUBLIC_MAX_AGE_SECONDS));
        $response->headers->set(
            'Cache-Control',
            'public, max-age='.$maxAge.', s-maxage='.$maxAge.', must-revalidate',
        );

        // Never emit a stale dynamic effective date; pages render config dates only.
        $response->headers->set('Vary', 'Accept-Language, Cookie', false);

        return $response;
    }

    /**
     * Mark a response as unusable by any cache, private or shared.
     */
    private function forbidStoring(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
