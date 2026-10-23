<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;

/**
 * Pseudonymous risk hint, NOT a unique person identifier or authentication proof.
 * NAT/shared devices can collide. Never block on this fingerprint alone.
 * Request::ip() relies on separately verified trusted-proxy configuration.
 */
final class DeviceFingerprintService
{
    public function __construct(private readonly Repository $config) {}

    public function fingerprint(Request $request): string
    {
        $key = $this->config->get('bot.fingerprint.key');
        if (is_string($key) && str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }
        if (! is_string($key) || strlen($key) < 32) {
            throw new \RuntimeException('Configure a dedicated fingerprint HMAC key of at least 32 bytes.');
        }

        $parts = [
            'bot-fingerprint-v1',
            $this->header((string) $request->header('User-Agent', '')),
            $this->header((string) $request->header('Accept-Language', '')),
            $this->network($request->ip()),
        ];

        // Length-prefix encoding prevents ambiguous concatenation; safe for arbitrary header bytes.
        $material = '';
        foreach ($parts as $part) {
            $material .= pack('N', strlen($part)).$part;
        }

        return hash_hmac('sha256', $material, $key);
    }

    private function header(string $value): string
    {
        return trim(preg_replace('/[\t\r\n ]+/', ' ', substr($value, 0, 2048)) ?? '');
    }

    private function network(?string $ip): string
    {
        $packed = $ip === null ? false : @inet_pton($ip);
        if ($packed === false) {
            throw new \RuntimeException('Cannot fingerprint a request without a valid client IP.');
        }

        // Normalize IPv4-mapped IPv6 before applying the same IPv4 /24 policy.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $packed = substr($packed, 12);
        }

        return strlen($packed) === 4
            ? 'v4:'.bin2hex(substr($packed, 0, 3)).'/24'
            : 'v6:'.bin2hex(substr($packed, 0, 8)).'/64';
    }
}
