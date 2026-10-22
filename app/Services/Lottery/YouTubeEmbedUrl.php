<?php

declare(strict_types=1);

namespace App\Services\Lottery;

/**
 * Strict allowlisted normaliser for an operator-supplied YouTube live stream URL.
 *
 * Only https URLs on the YouTube hosts below, carrying an 11-character video id,
 * are accepted. Anything else returns null so the live draw stays NOT_CONFIGURED
 * rather than rendering an attacker-chosen or malformed frame. The output is always
 * the privacy-enhanced embed form on youtube-nocookie.com.
 */
final class YouTubeEmbedUrl
{
    public const EMBED_BASE = 'https://www.youtube-nocookie.com/embed/';

    /** @var list<string> */
    private const ALLOWED_HOSTS = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'youtu.be',
        'youtube-nocookie.com',
        'www.youtube-nocookie.com',
    ];

    private const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    private const MAX_LENGTH = 2048;

    /**
     * Returns the normalised embed URL, or null when the input is not an allowed YouTube URL.
     */
    public static function normalize(?string $url): ?string
    {
        $videoId = self::videoId($url);

        return $videoId === null ? null : self::EMBED_BASE.$videoId;
    }

    /**
     * Extracts the 11-character video id from an allowed YouTube URL, or null.
     */
    public static function videoId(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        // Trim only layout whitespace. A NUL byte must survive to the control-character check below.
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

        if (! in_array($host, self::ALLOWED_HOSTS, true)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        $candidate = null;

        if ($host === 'youtu.be') {
            $candidate = ltrim($path, '/');
        } elseif (preg_match('#^/(embed|live|shorts)/([^/]+)$#', $path, $matches) === 1) {
            $candidate = $matches[2];
        } elseif ($path === '/watch' && isset($query['v']) && is_string($query['v'])) {
            $candidate = $query['v'];
        }

        if ($candidate === null || preg_match(self::ID_PATTERN, $candidate) !== 1) {
            return null;
        }

        return $candidate;
    }
}
