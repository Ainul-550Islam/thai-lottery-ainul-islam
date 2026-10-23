<?php

declare(strict_types=1);

namespace App\Services\Chat;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Anonymous visitor identity management for live chat.
 *
 * Raw tokens live only in the browser's HttpOnly cookie; only SHA-256 hex
 * digests are persisted or compared in the database.
 */
final class ChatIdentityService
{
    public const COOKIE_NAME = 'thailotto_chat_visitor';

    public const REQUEST_ATTR_NEW_TOKEN = '_chat_new_guest_token';

    public const TOKEN_LENGTH = 64;

    public function newToken(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }

    public function isWellFormed(?string $token): bool
    {
        if (! is_string($token) || strlen($token) !== self::TOKEN_LENGTH) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1;
    }

    public function tokenFromRequest(Request $request): ?string
    {
        $issued = $request->attributes->get(self::REQUEST_ATTR_NEW_TOKEN);
        if (is_string($issued) && $this->isWellFormed($issued)) {
            return $issued;
        }

        $cookie = $request->cookie(self::COOKIE_NAME);
        if (is_string($cookie) && $this->isWellFormed($cookie)) {
            return $cookie;
        }

        return null;
    }

    public function tokenHashFromRequest(Request $request): ?string
    {
        $token = $this->tokenFromRequest($request);

        if ($token === null) {
            return null;
        }

        return hash('sha256', $token);
    }

    public function buildCookie(string $token): Cookie
    {
        $ttlHours = max(1, (int) config('chat.guest.identity_ttl_hours', 72));
        $minutes = $ttlHours * 60;

        return cookie(
            name: self::COOKIE_NAME,
            value: $token,
            minutes: $minutes,
            path: '/',
            domain: config('session.domain'),
            secure: (bool) config('session.secure', false),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }
}
