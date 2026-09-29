<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\AuthLoginIdentifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/*
 * PROMPT 3 — safe format validation for the "Account ID or Email"
 * (and "Account No. or email") identifier inputs.
 *
 * This rule validates SHAPE ONLY. It never queries the database, so it
 * can never become an enumeration oracle: a syntactically valid but
 * unknown identifier and a syntactically valid known one are
 * indistinguishable at the validation layer. Existence is resolved
 * exclusively inside the auth services, which answer every
 * not-found the same generic way.
 */
final class AccountIdentifierRule implements ValidationRule
{
    /**
     * @param  bool  $allowAccountId  accept digits-only account ids
     * @param  bool  $allowUsername  accept the username dimension
     */
    public function __construct(
        private readonly bool $allowAccountId = true,
        private readonly bool $allowUsername = true,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('public_pages.identifier_invalid')->translate();

            return;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || mb_strlen($trimmed) > 255) {
            $fail('public_pages.identifier_invalid')->translate();

            return;
        }

        $kind = AuthLoginIdentifier::resolve($trimmed);

        // The rule accepts the dimensions it was configured to accept.
        $accepted = match ($kind) {
            AuthLoginIdentifier::AccountId => $this->allowAccountId,
            AuthLoginIdentifier::Email => true,
            AuthLoginIdentifier::Username => $this->allowUsername,
        };

        if (! $accepted) {
            $fail('public_pages.identifier_invalid')->translate();

            return;
        }

        // Shape-level sanity per dimension (still no existence checks).
        if ($kind === AuthLoginIdentifier::Username) {
            $min = (int) config('auth_security.identifiers.username_min_length', 3);
            $max = (int) config('auth_security.identifiers.username_max_length', 30);

            if (mb_strlen($trimmed) < $min || mb_strlen($trimmed) > $max) {
                $fail('public_pages.identifier_invalid')->translate();

                return;
            }
        }

        if ($kind === AuthLoginIdentifier::Email
            && filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false) {
            $fail('public_pages.identifier_invalid')->translate();
        }
    }
}
