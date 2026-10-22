<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalisation, keyed hashing and masking for the optional IC / national ID.
 *
 * The hash is an HMAC keyed with the application key, so it cannot be
 * reversed or brute-forced from a database dump without that key. It exists
 * only to detect duplicate registrations.
 */
final class NationalIdentity
{
    /** Accepted normalised shape: 6 to 32 letters or digits. */
    public const PATTERN = '/^[A-Z0-9]{6,32}$/';

    /**
     * Upper-cases the value and strips spaces and dashes.
     * Returns null when nothing usable remains.
     */
    public static function normalise(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = strtoupper((string) preg_replace('/[\s\-]+/', '', trim($raw)));

        return $value === '' ? null : $value;
    }

    public static function isValid(string $normalised): bool
    {
        return preg_match(self::PATTERN, $normalised) === 1;
    }

    /** Keyed HMAC of an already-normalised number. */
    public static function hash(string $normalised): string
    {
        return hash_hmac('sha256', $normalised, (string) config('app.key'));
    }

    /** Shows only the last four characters, e.g. "******1234". */
    public static function mask(string $normalised): string
    {
        $visible = substr($normalised, -4);

        return str_repeat('*', max(0, strlen($normalised) - 4)).$visible;
    }
}
