<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * Hardened Public App Link Service.
 *
 * Configured Android / iOS / PWA download links for public marketing pages.
 *
 * WHAT THIS VALIDATES, AND WHY IT IS NOT A STORE-HOST ALLOWLIST.
 *
 * This class previously required every Android URL to live on
 * play.google.com / market.android.com and every iOS URL on
 * apps.apple.com / itunes.apple.com. That is not a security boundary: the
 * value comes from the operator's own environment file, so an attacker who
 * can set it has already won. What the allowlist DID do was make three
 * entirely legitimate distributions impossible to configure - a self hosted
 * signed APK, an enterprise / MDM distribution page, and a TestFlight or
 * other beta link. The links() contract says the card renders what the
 * operator configured, and the allowlist silently contradicted it by
 * returning NOT_CONFIGURED for a perfectly valid https URL.
 *
 * The real risk here is the rendered href, so that is what is checked:
 *   - https only; javascript:, data:, file:, vbscript: and about: rejected,
 *   - a resolvable public host; localhost, 127.0.0.1, *.local and *.internal
 *     rejected so an operator cannot publish an unreachable or SSRF-shaped
 *     link,
 *   - protocol relative '//evil.test' rejected,
 *   - empty and whitespace only values dropped rather than rendered.
 * Anything that fails returns null, and a card with no surviving link reports
 * NOT_CONFIGURED instead of showing a fabricated store button.
 */
class PublicAppLinkService
{
    /**
     * @return array{
     *     status: string,
     *     android: string|null,
     *     ios: string|null,
     *     pwa: string|null,
     *     message: string,
     * }
     */
    public function links(): array
    {
        $android = $this->validateAndroidUrl(config('home.app_links.android'));
        $ios = $this->validateIosUrl(config('home.app_links.ios'));
        $pwa = $this->validatePwaUrl(config('home.app_links.pwa'));

        $any = $android !== null || $ios !== null || $pwa !== null;

        return [
            'status' => $any ? 'CONFIGURED' : 'NOT_CONFIGURED',
            'android' => $android,
            'ios' => $ios,
            'pwa' => $pwa,
            'message' => $any ? '' : 'App download links are not configured.',
        ];
    }

    public function validateAndroidUrl(mixed $value): ?string
    {
        return $this->safeUrl($value);
    }

    public function validateIosUrl(mixed $value): ?string
    {
        return $this->safeUrl($value);
    }

    public function validatePwaUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || str_starts_with($trimmed, '//')) {
            return null;
        }

        // PWA manifests/installers are typically site-relative (/manifest.webmanifest) or local HTTPS
        if (str_starts_with($trimmed, '/') && ! str_starts_with($trimmed, '/\\')) {
            return $trimmed;
        }

        return $this->safeUrl($trimmed);
    }

    private function safeUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        // Reject dangerous schemes
        if (preg_match('#^(javascript|data|file|vbscript|about):#i', $trimmed) === 1) {
            return null;
        }

        $parsed = parse_url($trimmed);
        if ($parsed === false || ! isset($parsed['scheme'])) {
            return null;
        }

        $scheme = strtolower($parsed['scheme']);
        if ($scheme !== 'https') {
            return null;
        }

        $host = strtolower($parsed['host'] ?? '');
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return null;
        }

        return filter_var($trimmed, FILTER_VALIDATE_URL) ? $trimmed : null;
    }
}
