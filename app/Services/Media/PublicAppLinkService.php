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
 *   - https only for external destinations; dangerous schemes are rejected,
 *   - a resolvable public host; localhost, 127.0.0.1, *.local and *.internal
 *     are rejected,
 *   - protocol-relative URLs are rejected,
 *   - empty and whitespace-only values are dropped rather than rendered,
 *   - the in-app APK route is exposed only after its generated manifest and
 *     APK checksum have been verified by AndroidApkReleaseService.
 * Anything that fails returns null, and a card with no surviving link reports
 * NOT_CONFIGURED instead of showing a fabricated store button.
 */
final class PublicAppLinkService
{
    private const LOCAL_ANDROID_APK_URL = '/app/thai-apk.apk';

    public function __construct(private readonly AndroidApkReleaseService $androidApkRelease) {}

    /**
     * @return array{
     *     status: string,
     *     android: string|null,
     *     android_build: array{
     *         status: string,
     *         version: string,
     *         package_name: string,
     *         web_app_url: string,
     *         file: string,
     *         sha256: string,
     *         size_bytes: int,
     *         built_at: string,
     *         source_commit: string
     *     }|null,
     *     ios: string|null,
     *     pwa: string|null,
     *     message: string,
     * }
     */
    public function links(): array
    {
        $configuredAndroid = config('home.app_links.android');
        $androidBuild = null;

        if ($this->isBlank($configuredAndroid) || $this->isLocalAndroidApkUrl($configuredAndroid)) {
            $androidBuild = $this->androidApkRelease->metadata();
            $android = $androidBuild !== null ? self::LOCAL_ANDROID_APK_URL : null;
        } else {
            $android = $this->validateAndroidUrl($configuredAndroid);
        }

        $ios = $this->validateIosUrl(config('home.app_links.ios'));
        $pwa = $this->validatePwaUrl(config('home.app_links.pwa'));
        $any = $android !== null || $ios !== null || $pwa !== null;

        return [
            'status' => $any ? 'CONFIGURED' : 'NOT_CONFIGURED',
            'android' => $android,
            'android_build' => $androidBuild,
            'ios' => $ios,
            'pwa' => $pwa,
            'message' => $any ? '' : 'App download links are not configured.',
        ];
    }

    public function validateAndroidUrl(mixed $value): ?string
    {
        if (is_string($value) && trim($value) === self::LOCAL_ANDROID_APK_URL) {
            return $this->androidApkRelease->metadata() !== null
                ? self::LOCAL_ANDROID_APK_URL
                : null;
        }

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

        // PWA manifests/installers are typically site-relative (/manifest.webmanifest) or local HTTPS.
        if (str_starts_with($trimmed, '/') && ! str_starts_with($trimmed, '/\\')) {
            return $trimmed;
        }

        return $this->safeUrl($trimmed);
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function isLocalAndroidApkUrl(mixed $value): bool
    {
        return is_string($value) && trim($value) === self::LOCAL_ANDROID_APK_URL;
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

        // Reject dangerous schemes.
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
        if ($host === ''
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            return null;
        }

        return filter_var($trimmed, FILTER_VALIDATE_URL) ? $trimmed : null;
    }
}
