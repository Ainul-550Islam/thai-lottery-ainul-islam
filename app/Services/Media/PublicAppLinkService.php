<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * Configured Android / iOS / PWA links for the public Home CTA.
 *
 * NEVER fabricates Google Play or App Store URLs. Unconfigured platforms are
 * omitted from the payload so the view does not render a fake download button.
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
        $android = $this->safeUrl(config('home.app_links.android'));
        $ios = $this->safeUrl(config('home.app_links.ios'));
        $pwa = $this->safeUrl(config('home.app_links.pwa'));

        $any = $android !== null || $ios !== null || $pwa !== null;

        return [
            'status' => $any ? 'CONFIGURED' : 'NOT_CONFIGURED',
            'android' => $android,
            'ios' => $ios,
            'pwa' => $pwa,
            'message' => $any ? '' : 'App download links are not configured.',
        ];
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

        // Absolute http(s) or site-relative path only.
        if (preg_match('#^https?://#i', $trimmed) === 1) {
            return $trimmed;
        }

        if (str_starts_with($trimmed, '/')) {
            return $trimmed;
        }

        return null;
    }
}
