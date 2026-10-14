<?php

declare(strict_types=1);

namespace App\Services\Web;

/**
 * Validated public social-profile links for the site footer.
 *
 * A platform link is shown only when the operator configured an https URL on that
 * platform's own domain. Anything else resolves to null, and the view keeps its neutral
 * fallback. This stops a mistyped or hostile env value from putting an off-site link on
 * every page.
 */
final class SocialLinkService
{
    /** @var array<string, list<string>> */
    private const HOSTS = [
        'youtube' => ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'],
        'facebook' => ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'fb.com', 'www.fb.com'],
        'tiktok' => ['tiktok.com', 'www.tiktok.com', 'm.tiktok.com'],
    ];

    private const MAX_LENGTH = 2048;

    public static function url(string $platform): ?string
    {
        $raw = config('social.'.$platform);

        return self::validate($platform, is_string($raw) ? $raw : null);
    }

    /**
     * Returns the trimmed URL when it is an https link on the platform's domain, otherwise null.
     */
    public static function validate(string $platform, ?string $url): ?string
    {
        if (! array_key_exists($platform, self::HOSTS) || $url === null) {
            return null;
        }

        $url = trim($url, " \t\r\n");

        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, self::HOSTS[$platform], true)) {
            return null;
        }

        return $url;
    }
}
