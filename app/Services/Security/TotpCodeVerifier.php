<?php

declare(strict_types=1);

namespace App\Services\Security;

use Psr\Clock\ClockInterface;
use RuntimeException;
use SensitiveParameter;

/**
 * Exact RFC 4226 / RFC 6238 TOTP verification (SHA-1, 30-second step, 6 digits).
 *
 * WHY NOT JUST THE LIBRARY
 * The pinned OTPHP library is the reference implementation and it is exercised
 * directly by the test vectors. This adapter exists to impose the stricter
 * deployment rules the library deliberately does NOT impose:
 *
 *  - the submitted code must be EXACTLY six ASCII digits — no whitespace, no
 *    newline, no NUL, no full-width or Arabic-Indic digits, no sign, no
 *    exponent. Loose parsing is how " 081804\n" becomes a second code path.
 *  - the secret must be CANONICAL 160-bit Base32: exactly 32 characters of
 *    [A-Z2-7], no lowercase, no padding, no surrounding whitespace. A secret
 *    stored in a non-canonical form is a secret whose provenance is unknown.
 *  - the acceptance window is exactly the previous, current and next step.
 *    Arbitrary "leeway" quietly multiplies the guessing surface; three steps
 *    is the documented tolerance for clock skew and nothing more.
 *  - the server clock is read ONCE per verification and a broken clock is an
 *    operational error (RuntimeException), never a silent mismatch and never
 *    a success. Swallowing a clock failure would turn an incident into a
 *    mysterious lockout — or worse, into an acceptance.
 *
 * WHAT THIS CLASS DOES NOT DO
 * Replay consumption. A code matching twice inside its window is reported
 * twice; burning a used code is the caller's responsibility (the action-proof
 * and challenge layers own per-account state). Keeping this class stateless
 * is what makes the RFC vectors testable in isolation.
 */
final class TotpCodeVerifier
{
    /**
     * Largest supported Unix timestamp: 9999-12-31T23:59:59Z, the same ceiling
     * the IP-rule subsystem uses, so "no supported instant" means one thing
     * across the codebase. Beyond it the 8-byte counter stays well inside
     * signed 64-bit range, so arithmetic never overflows before this guard.
     */
    public const MAX_TIMESTAMP = 253402300799;

    private const PERIOD = 30;

    private const DIGITS = 6;

    private const SECRET_BYTES = 20; // 160 bits -> exactly 32 Base32 chars

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    /**
     * Return the time step (HOTP counter) whose code equals $code, or null
     * when the code, the secret, or the window does not match.
     *
     * @throws RuntimeException when the server clock is outside the supported range
     */
    public function matchingTimeStep(
        #[SensitiveParameter] string $secret,
        #[SensitiveParameter] string $code,
    ): ?int {
        // Read the clock exactly once: a second read could straddle a step
        // boundary and make the same call answer two different windows.
        // A throwing clock is an operational error and is NOT swallowed.
        $timestamp = $this->clock->now()->getTimestamp();

        if ($timestamp < 0 || $timestamp > self::MAX_TIMESTAMP) {
            throw new RuntimeException('TOTP server clock is outside the supported range.');
        }

        if (preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            return null;
        }

        $key = $this->decodeSecret($secret);
        if ($key === null) {
            return null;
        }

        $current = intdiv($timestamp, self::PERIOD);

        foreach ([$current - 1, $current, $current + 1] as $step) {
            if ($step < 0) {
                continue;
            }

            if (hash_equals($this->hotp($key, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Generate a fresh canonical 160-bit Base32 secret (32 chars, [A-Z2-7]).
     */
    public function generateSecret(): string
    {
        $bytes = random_bytes(self::SECRET_BYTES);
        $bits = '';

        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $secret .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $secret;
    }

    /**
     * Decode a canonical secret, or null for any non-canonical shape.
     */
    private function decodeSecret(#[SensitiveParameter] string $secret): ?string
    {
        if (preg_match('/\A[A-Z2-7]{32}\z/', $secret) !== 1) {
            return null;
        }

        $bits = '';
        foreach (str_split($secret) as $char) {
            $value = strpos(self::BASE32_ALPHABET, $char);
            if ($value === false) {
                return null;
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return strlen($bytes) === self::SECRET_BYTES ? $bytes : null;
    }

    /**
     * RFC 4226 HOTP: HMAC-SHA-1 over the 8-byte big-endian counter, dynamic
     * truncation, modulo 10^6, zero-padded to six digits.
     */
    private function hotp(#[SensitiveParameter] string $key, int $counter): string
    {
        $binary = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord($hash[19]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }
}
