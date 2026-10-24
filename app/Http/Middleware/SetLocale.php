<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\SupportedLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to negotiate and set the active application locale.
 *
 * Supports query parameters (?lang=bn or ?locale=bn), session persistence,
 * and cookies — every value validated through App\Support\SupportedLocale, which
 * is the single allow-list (en, th, bn).
 *
 * WHY THE LOCAL LIST WAS REMOVED
 * This class used to validate against a private ['en', 'th'] constant. Bangla was
 * added to SupportedLocale and lang/bn was populated, but `?lang=bn`, `/locale/bn`,
 * the session value and the cookie were all silently rejected here, so a Bangla
 * request rendered in the previously active language. Validating through
 * SupportedLocale means a language is enabled in exactly one place.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = null;

        // 1. Explicit query parameter (?lang=bn or ?locale=bn)
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
