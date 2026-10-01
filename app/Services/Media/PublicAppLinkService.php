<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * Hardened Public App Link Service.
 *
 * Configured Android / iOS / PWA download links for public marketing pages.
 * Validates URLs against scheme, host, and security allowlists.
 * Drops malformed, localhost, javascript:, data:, and unverified URLs.
 */
class PublicAppLinkService
{
    private const ALLOWED_ANDROID_HOSTS = [
        'play.google.com',
        'market.android.com',
    ];

    private const ALLOWED_IOS_HOSTS = [
        'apps.apple.com',
        'itunes.apple.com',
    ];

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
        $url = $this->safeUrl($value);
        if ($url === null) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null) {
            return null;
        }

        $host = strtolower($host);
        foreach (self::ALLOWED_ANDROID_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return $url;
            }
        }

        // Also permit local app domain link
        $appHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);
        if ($appHost !== null && $host === strtolower($appHost)) {
            return $url;
        }

        return null;
    }

    public function validateIosUrl(mixed $value): ?string
    {
        $url = $this->safeUrl($value);
        if ($url === null) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null) {
            return null;
        }

        $host = strtolower($host);
        foreach (self::ALLOWED_IOS_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return $url;
            }
        }

        $appHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);
        if ($appHost !== null && $host === strtolower($appHost)) {
            return $url;
        }

        return null;
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
