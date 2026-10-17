<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Str;
use InvalidArgumentException;

class TotpMfaService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const TIME_STEP_SECONDS = 30;
    private const TOTP_DIGITS = 6;

    /**
     * Generate a new random Base32 secret key for Google Authenticator / Authy.
     */
    public function generateSecret(int $length = 32): string
    {
        $secret = '';
        $alphabetLength = strlen(self::BASE32_ALPHABET);

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $secret;
    }

    /**
     * Generate otpauth:// URI for QR code generation.
     */
    public function getOtpAuthUri(string $secret, string $accountName, string $issuer = 'ThaiLotteryEnterprise'): string
    {
        $encodedIssuer = rawurlencode($issuer);
        $encodedAccount = rawurlencode($accountName);

        return "otpauth://totp/{$encodedIssuer}:{$encodedAccount}?secret={$secret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Verify a 6-digit TOTP code against a Base32 secret with ±1 time-step drift window.
     */
    public function verifyCode(string $secret, string $code, ?int $timestamp = null): bool
    {
        $sanitizedCode = preg_replace('/[^0-9]/', '', $code) ?? '';
        if (strlen($sanitizedCode) !== self::TOTP_DIGITS) {
            return false;
        }

        $time = $timestamp ?? time();
        $currentCounter = (int) floor($time / self::TIME_STEP_SECONDS);

        // Check current time window and ±1 drift windows (30 seconds before / after)
        for ($offset = -1; $offset <= 1; $offset++) {
            $counter = $currentCounter + $offset;
            $expectedCode = $this->calculateTotpAtCounter($secret, $counter);

            if (hash_equals($expectedCode, $sanitizedCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate an array of 8 secure single-use recovery codes.
     *
     * @return array<int, string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(Str::random(4) . '-' . Str::random(4));
        }

        return $codes;
    }

    /**
     * Calculate 6-digit TOTP code for a specific counter using HMAC-SHA1 (RFC 6238 / RFC 4226).
     */
    public function calculateTotpAtCounter(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $counterBytes = pack('N*', 0) . pack('N*', $counter);

        $hash = hash_hmac('sha1', $counterBytes, $key, true);

        // Dynamic truncation (RFC 4226 Section 5.4)
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF);

        $otp = $binary % (10 ** self::TOTP_DIGITS);

        return str_pad((string) $otp, self::TOTP_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Decode a Base32 encoded string into raw bytes.
     */
    private function base32Decode(string $base32): string
    {
        $base32 = strtoupper(rtrim($base32, '='));
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        for ($i = 0, $len = strlen($base32); $i < $len; $i++) {
            $char = $base32[$i];
            $val = strpos(self::BASE32_ALPHABET, $char);

            if ($val === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
