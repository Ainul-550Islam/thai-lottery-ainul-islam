<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to negotiate and set the active application locale (en / th).
 *
 * Supports query parameters (?lang=th or ?locale=th), session persistence,
 * and cookies with strict allowlist validation ('en', 'th').
 */
class SetLocale
{
    /**
     * Supported application locales.
     */
    private const SUPPORTED_LOCALES = ['en', 'th'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = null;

        // 1. Explicit query parameter (?lang=th)
        $queryLocale = $request->query('lang') ?? $request->query('locale');
        if (is_string($queryLocale) && in_array($queryLocale, self::SUPPORTED_LOCALES, true)) {
            $locale = $queryLocale;
            if ($request->hasSession()) {
                $request->session()->put('locale', $locale);
            }
        }

        // 2. Session-persisted locale
        if ($locale === null && $request->hasSession() && $request->session()->has('locale')) {
            $sessionLocale = (string) $request->session()->get('locale');
            if (in_array($sessionLocale, self::SUPPORTED_LOCALES, true)) {
                $locale = $sessionLocale;
            }
        }

        // 3. Cookie-persisted locale
        if ($locale === null && $request->hasCookie('locale')) {
            $cookieLocale = (string) $request->cookie('locale');
            if (in_array($cookieLocale, self::SUPPORTED_LOCALES, true)) {
                $locale = $cookieLocale;
            }
        }

        if ($locale !== null) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
