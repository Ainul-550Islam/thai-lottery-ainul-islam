<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every named reason the conversion lane refuses a request. The backing values
 * are the stable machine codes API clients, alerting rules and the audit trail
 * key on; they may not change. ConversionException::errorCode() speaks in
 * exactly these codes, so a refusal is always one of these cases.
 */
enum WalletConversionRejectionReason: string
{
    case BelowMinimum = 'below_minimum';
    case AboveMaximum = 'above_maximum';
    case AmountInvalid = 'amount_invalid';
    case DailyLimitExceeded = 'daily_limit_exceeded';
    case MonthlyLimitExceeded = 'monthly_limit_exceeded';
    case InsufficientBalance = 'insufficient_balance';
    case AccountSuspended = 'account_suspended';
    case SelfExclusionActive = 'self_exclusion_active';
    case WalletLocked = 'wallet_locked';
    case KycNotVerified = 'kyc_not_verified';
    case FeatureDisabled = 'feature_disabled';
    case DirectionDisabled = 'direction_disabled';
    case QuoteExpired = 'quote_expired';
    case QuoteNotFound = 'quote_not_found';
    case IdempotencyConflict = 'idempotency_conflict';
    case RateLimited = 'rate_limited';
    // The lane is denominated in one currency and there is no FX here. A request in
    // another denomination is a mismatch, not a conversion.
    case CurrencyMismatch = 'currency_mismatch';
    // No usable `conversions.currency`: the lane cannot price anything at all.
    case CurrencyNotConfigured = 'currency_not_configured';
    // The published tariff is missing or not valid. Distinct from FeatureDisabled: the
    // lane may be switched on and still be unable to price anything, and telling a
    // member "unavailable" when an operator has simply unpublished the fee row sends
    // support looking in the wrong place.
    case FeeRuleInvalid = 'fee_rule_invalid';

    public function label(): string
    {
        return match ($this) {
            self::BelowMinimum => 'Below minimum amount',
            self::AboveMaximum => 'Above maximum amount',
            self::AmountInvalid => 'Invalid amount',
            self::DailyLimitExceeded => 'Daily limit exceeded',
            self::MonthlyLimitExceeded => 'Monthly limit exceeded',
            self::InsufficientBalance => 'Insufficient balance',
            self::AccountSuspended => 'Account suspended',
            self::SelfExclusionActive => 'Self-exclusion active',
            self::WalletLocked => 'Wallet locked',
            self::KycNotVerified => 'Identity not verified',
            self::FeatureDisabled => 'Conversion unavailable',
            self::DirectionDisabled => 'This direction is unavailable',
            self::QuoteExpired => 'Quote expired',
            self::QuoteNotFound => 'Quote not found',
            self::IdempotencyConflict => 'Idempotency key conflict',
            self::RateLimited => 'Too many attempts',
            self::CurrencyMismatch => 'Currency mismatch',
            self::CurrencyNotConfigured => 'Conversions are not available in any currency right now',
            self::FeeRuleInvalid => 'The conversion fee schedule is unavailable',
        };
    }

    /**
     * The HTTP status the API layer answers with. A refusal is never a 500:
     * a 500 pages an operator for a member's own situation.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::IdempotencyConflict => 409,
            self::RateLimited => 429,
            self::FeatureDisabled,
            self::DirectionDisabled,
            // A lane with no valid currency, or no valid price, is a service that is
            // down, not a request the member got wrong.
            self::CurrencyNotConfigured,
            self::FeeRuleInvalid,
            self::AccountSuspended,
            self::SelfExclusionActive,
            self::WalletLocked,
            self::KycNotVerified => 403,
            default => 422,
        };
    }

    /**
     * True when the member can resolve this themselves: change the amount,
     * top up, verify identity, or wait out a window.
     */
    public function isUserCorrectable(): bool
    {
        return match ($this) {
            self::BelowMinimum,
            self::AboveMaximum,
            self::AmountInvalid,
            self::DailyLimitExceeded,
            self::MonthlyLimitExceeded,
            self::InsufficientBalance,
            self::QuoteExpired,
            self::QuoteNotFound,
            self::RateLimited => true,
            default => false,
        };
    }

    /**
     * The translation key for the member-facing explanation, when one exists.
     */
    public function messageKey(): ?string
    {
        // The `rejection_` keys are the ones the lang files actually carry (they are
        // also what the engine's refusal messages and the validation messages use), so
        // the member-facing reason and the machine code resolve to the same sentence
        // instead of two near-copies that can drift apart.
        return 'wallet_conversion.rejection_'.$this->value;
    }

    /**
     * Stable translation key for the member-facing conversion screens.
     *
     * The labels live in lang/<locale>/wallet_conversion.php next to every other
     * string this lane shows, so there is no second translation namespace to drift.
     */
    public function labelKey(): string
    {
        return "wallet_conversion.rejection_{$this->value}";
    }
}
