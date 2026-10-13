<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\WalletConversionDirection;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts only the conversion directions this deployment currently offers.
 *
 * WHY NOT `Rule::enum(...)`
 * The enum rule answers "is this a direction that exists in the codebase", which
 * is a different question from "may this member use it right now". A direction
 * can exist and be switched off — that is the normal state during a promotion
 * review or after a settlement incident — and a request for a switched-off
 * direction must fail with a message that says so, not with a generic
 * "invalid selection" that sends the member hunting for a typo.
 *
 * WHY THE MESSAGE NAMES THE AVAILABLE DIRECTIONS
 * The most common cause of this failure is a client built against a deployment
 * where both directions were on. Telling the caller exactly which directions are
 * open turns a support ticket into a one-line fix, and it is information the
 * public fee page already discloses.
 */
class ConversionDirectionRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! $value instanceof WalletConversionDirection) {
            $fail('The :attribute must be one of: '.$this->availableList().'.');

            return;
        }

        $direction = $value instanceof WalletConversionDirection
            ? $value
            : WalletConversionDirection::tryFrom(trim((string) $value));

        if ($direction === null) {
            $fail('The :attribute must be one of: '.$this->availableList().'.');

            return;
        }

        if (! (bool) config('conversions.enabled', true)) {
            $fail('Wallet conversions are temporarily unavailable.');

            return;
        }

        if (! (bool) config('conversions.directions.'.$direction->value.'.enabled', true)) {
            $fail(sprintf(
                'The %s direction is temporarily unavailable. Currently available: %s.',
                $direction->label(),
                $this->availableList(),
            ));
        }
    }

    private function availableList(): string
    {
        $available = [];

        foreach (WalletConversionDirection::cases() as $direction) {
            if ((bool) config('conversions.directions.'.$direction->value.'.enabled', true)) {
                $available[] = $direction->value;
            }
        }

        return $available === [] ? 'none (conversions are disabled)' : implode(', ', $available);
    }

    public function __toString(): string
    {
        return 'conversion_direction';
    }
}
