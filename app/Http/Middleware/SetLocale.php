<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\SupportedLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Negotiates and sets the active application locale (en / th / bn).
 *
 * Priority: explicit query parameter (?lang=bn or ?locale=bn), then the
 * session-persisted locale, then the cookie-persisted locale. Every value is
 * checked against the SupportedLocale allow-list before it is used.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = null;

        // 1. Explicit query parameter (?lang=bn)
        $queryLocale = SupportedLocale::normalise($request->query('lang') ?? $request->query('locale'));
        if ($queryLocale !== null) {
            $locale = $queryLocale;
            if ($request->hasSession()) {
                $request->session()->put('locale', $locale);
            }
        }

        // 2. Session-persisted locale
        if ($locale === null && $request->hasSession() && $request->session()->has('locale')) {
            $locale = SupportedLocale::normalise($request->session()->get('locale'));
        }

        // 3. Cookie-persisted locale
        if ($locale === null && $request->hasCookie('locale')) {
            $locale = SupportedLocale::normalise($request->cookie('locale'));
        }

        if ($locale !== null) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
