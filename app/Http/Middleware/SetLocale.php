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
 * and cookies with strict allowlist validation. The allowlist itself lives
 * in SupportedLocale — the single source the switch route also validates
 * through — so adding a language happens in exactly one place. (This
 * middleware used to carry its own ['en', 'th'] list, which silently
 * excluded Bangla (bn) after the language shipped: bn translations and
 * the SupportedLocale contract existed, but no request could select it.)
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = null;

        // 1. Explicit query parameter (?lang=th)
        $queryLocale = $request->query('lang') ?? $request->query('locale');
        if (is_string($queryLocale) && SupportedLocale::isSupported($queryLocale)) {
            $locale = $queryLocale;
            if ($request->hasSession()) {
                $request->session()->put('locale', $locale);
            }
        }

        // 2. Session-persisted locale
        if ($locale === null && $request->hasSession() && $request->session()->has('locale')) {
            $sessionLocale = (string) $request->session()->get('locale');
            if (SupportedLocale::isSupported($sessionLocale)) {
                $locale = $sessionLocale;
            }
        }

        // 3. Cookie-persisted locale
        if ($locale === null && $request->hasCookie('locale')) {
            $cookieLocale = (string) $request->cookie('locale');
            if (SupportedLocale::isSupported($cookieLocale)) {
                $locale = $cookieLocale;
            }
        }

        if ($locale !== null) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
