<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\LiveStreamProvider;

/**
 * Turns an operator-supplied public video URL into an embeddable player URL for a known provider.
 *
 * Rules shared by every provider:
 *  - https only, no credentials, default port only, bounded length, no whitespace or control bytes;
 *  - host must be on that provider's allowlist (lookalike domains are rejected);
 *  - only canonical video identifiers are accepted, and the output is rebuilt from them, so
 *    tracking parameters and arbitrary query strings never reach the page.
 *
 * TikTok LIVE pages (/@user/live) have no embeddable identifier and are rejected on purpose:
 * the card must not show a broken or third-party page as if it were the live feed.
 */
final class LiveStreamEmbedResolver
{
    public const FACEBOOK_PLUGIN_BASE = 'https://www.facebook.com/plugins/video.php';

    public const TIKTOK_EMBED_BASE = 'https://www.tiktok.com/embed/v2/';

    private const MAX_LENGTH = 2048;

    /** @var list<string> */
    private const FACEBOOK_HOSTS = ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'web.facebook.com'];

    /** @var list<string> */
    private const TIKTOK_HOSTS = ['tiktok.com', 'www.tiktok.com', 'm.tiktok.com'];

    public static function resolve(LiveStreamProvider $provider, ?string $url): ?string
    {
        return match ($provider) {
            LiveStreamProvider::YouTube => YouTubeEmbedUrl::normalize($url),
            LiveStreamProvider::Facebook => self::facebookEmbed($url),
            LiveStreamProvider::TikTok => self::tiktokEmbed($url),
        };
    }

    /**
     * Accepts facebook.com watch, watch/live, /{page}/videos/{id}, /videos/{id} and /reel/{id}.
     * Short fb.watch links are not accepted because their token format is not a stable identifier.
     */
    public static function facebookVideoId(?string $url): ?string
    {
        $parts = self::httpsParts($url, self::FACEBOOK_HOSTS);

        if ($parts === null) {
            return null;
        }

        $path = $parts['path'];

        if (in_array($path, ['/watch', '/watch/live'], true)
            && isset($parts['query']['v'])
            && is_string($parts['query']['v'])
            && preg_match('/^\d{5,30}$/', $parts['query']['v']) === 1) {
            return $parts['query']['v'];
        }

        if (preg_match('#^/(?:[A-Za-z0-9._\-]{1,80}/)?videos/(\d{5,30})$#', $path, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#^/reel/(\d{5,30})$#', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public static function facebookEmbed(?string $url): ?string
    {
        $videoId = self::facebookVideoId($url);

        if ($videoId === null) {
            return null;
        }

        $canonical = 'https://www.facebook.com/watch/?v='.$videoId;

        return self::FACEBOOK_PLUGIN_BASE.'?href='.rawurlencode($canonical).'&show_text=false&width=560';
    }

    /**
     * Accepts /@user/video/{id}, /embed/{id} and /embed/v2/{id}. TikTok LIVE URLs are rejected.
     */
    public static function tiktokVideoId(?string $url): ?string
    {
        $parts = self::httpsParts($url, self::TIKTOK_HOSTS);

        if ($parts === null) {
            return null;
        }

        $path = $parts['path'];

        if (preg_match('#^/@[A-Za-z0-9._]{2,24}/video/(\d{15,25})$#', $path, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#^/embed(?:/v2)?/(\d{15,25})$#', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public static function tiktokEmbed(?string $url): ?string
    {
        $videoId = self::tiktokVideoId($url);

        return $videoId === null ? null : self::TIKTOK_EMBED_BASE.$videoId;
    }

    /**
     * Validates the URL shape shared by all providers and returns its trimmed path (no trailing
     * slash) and decoded query, or null when any rule fails.
     *
     * @param  list<string>  $allowedHosts
     * @return array{path: string, query: array<string, mixed>}|null
     */
    private static function httpsParts(?string $url, array $allowedHosts): ?array
    {
        if ($url === null) {
            return null;
        }

        // Trim layout whitespace only, so a NUL byte stays visible to the control-character check.
        $url = trim($url, " \t\r\n");

        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, $allowedHosts, true)) {
            return null;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        return [
            'path' => rtrim((string) ($parts['path'] ?? ''), '/'),
            'query' => $query,
        ];
    }
}
