<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PublicLegalHeaders — cache policy for public legal/informational pages.
 *
 * Applied only to safe GET responses for /about, /vision, and /terms.
 *  - Anonymous (guest) GETs receive a short PUBLIC cache window so CDNs/browsers
 *    may reuse the HTML; Vary stays on Cookie/Accept-Language where present.
 *  - Authenticated or non-GET requests are never publicly cached (no-store),
 *    and nothing ever sets Cache-Control: private for shared caches to store
 *    under a private key incorrectly — private sessions simply must not store.
 *
 * Does not weaken SecurityHeaders (X-Frame-Options etc.) registered globally
 * in bootstrap/app.php. No PII, secrets, or admin metrics are added here.
 */
final class PublicLegalHeaders
{
    public const CACHEABLE_PATHS = ['about', 'vision', 'terms'];

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
            $response->headers->set('Cache-Control', 'no-store, no-cache, max-age=0');
            $response->headers->set('Pragma', 'no-cache');

            return $response;
        }

        // Logged-in players must never be stored by shared caches.
        // Guest GETs (even with a session cookie from StartSession) may be
        // publicly cached with Vary: Cookie — the response carries no PII.
        if ($request->user() !== null) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, max-age=0');
            $response->headers->set('Pragma', 'no-cache');

            return $response;
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
}
