<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/*
 * PROMPT 3 — the password-strength policy.
 *
 * Compatible with the existing password contract (the platform has
 * always required min 8 with letters and numbers), expressed as a
 * configurable, self-contained rule so the new registration and
 * password-reset surfaces enforce exactly one policy.
 *
 * The rule also rejects a small list of trivially compromised
 * passwords. It never logs the value it validates.
 */
final class StrongPasswordRule implements ValidationRule
{
    /**
     * Common/compromised passwords that must never pass, regardless of
     * length/complexity. Kept intentionally small and conservative so
     * legitimate existing passwords are never locked out.
     */
    private const COMMON_PASSWORDS = [
        'password',
        'password1',
        'password123',
        '123456',
        '12345678',
        '123456789',
        '1234567890',
        'qwerty',
        'qwerty123',
        'letmein',
        'welcome',
        'welcome1',
        'admin',
        'admin123',
        'iloveyou',
        '111111',
        '000000',
        'abc123',
        'thailand',
        'thailotto',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('public_pages.password_required')->translate();

            return;
        }

        $min = max(1, (int) config('auth_security.passwords.min_length', 8));

        if (mb_strlen($value) < $min) {
            $fail('public_pages.password_min_length')->translate(['min' => $min]);

            return;
        }

        if (mb_strlen($value) > 255) {
            $fail('public_pages.password_invalid')->translate();

            return;
        }

        if (preg_match('/[A-Za-z]/', $value) !== 1 || preg_match('/\d/', $value) !== 1) {
            $fail('public_pages.password_letters_numbers')->translate();

            return;
        }

        if (in_array(mb_strtolower($value), self::COMMON_PASSWORDS, true)) {
            $fail('public_pages.password_common')->translate();
        }
    }
}
