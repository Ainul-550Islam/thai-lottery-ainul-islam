<?php

declare(strict_types=1);

namespace App\Enums;

/*
 * PROMPT 3 — the login identifier taxonomy.
 *
 * The member login accepts "Account ID or Email Address". The existing
 * surface already resolved username-or-email; PROMPT 3 adds the numeric
 * account id as a first-class dimension while keeping username and
 * email exactly as they were.
 *
 * SAFE LOOKUP BEHAVIOR: classification is a pure string-shape decision.
 * It never reveals — in message, timing or response shape — whether the
 * classified identifier matches an existing account. The login service
 * turns every classification into the same generic failure.
 */
enum AuthLoginIdentifier: string
{
    /** Digits-only string: the numeric account (user) id. */
    case AccountId = 'account_id';

    /** RFC-shaped email address. */
    case Email = 'email';

    /** Anything else: the pre-existing username dimension. */
    case Username = 'username';

    /**
     * Classify a raw identifier string.
     *
     * Order: digits-only -> account id; valid email -> email; else
     * username. The regexes come from config so the shapes are policy,
     * not hardcode.
     */
    public static function resolve(string $input): self
    {
        $value = trim($input);

        $accountPattern = (string) config('auth_security.identifiers.account_id_pattern', '/^\d{1,15}$/');

        if ($value !== '' && preg_match($accountPattern, $value) === 1) {
            return self::AccountId;
        }

        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return self::Email;
        }

        return self::Username;
    }

    /**
     * The normalized, canonical form of the raw input for this class:
     * emails are lowercased; account ids keep their digits; usernames
     * are trimmed only (case is significant to the column).
     */
    public function normalize(string $input): string
    {
        $value = trim($input);

        return match ($this) {
            self::Email => mb_strtolower($value),
            self::AccountId, self::Username => $value,
        };
    }

    /**
     * The users-table column this class authenticates against.
     */
    public function credentialField(): string
    {
        return match ($this) {
            self::AccountId => 'id',
            self::Email => 'email',
            self::Username => 'username',
        };
    }

    /**
     * Candidate credential columns to attempt, in order. A digits-only
     * identifier also tries username because legacy usernames may be
     * numeric; trying it costs nothing and discloses nothing (all
     * failures collapse into one generic response).
     *
     * @return list<string>
     */
    public function candidateFields(): array
    {
        return match ($this) {
            self::AccountId => ['id', 'username'],
            self::Email => ['email'],
            self::Username => ['username'],
        };
    }
}
