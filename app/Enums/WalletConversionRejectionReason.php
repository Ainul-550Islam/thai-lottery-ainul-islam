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
    case FeeRuleInvalid = 'fee_rule_invalid';
    case CurrencyNotConfigured = 'currency_not_configured';
    case CurrencyMismatch = 'currency_mismatch';

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
            self::FeeRuleInvalid => 'Fee schedule not configured',
            self::CurrencyNotConfigured => 'Currency not configured',
            self::CurrencyMismatch => 'Currency mismatch',
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
            self::DirectionDisabled => 503,
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

    public function labelKey(): string
    {
        return 'wallet_conversion.rejection_'.$this->value;
    }

    /**
     * The translation key for the member-facing explanation, when one exists.
     */
    public function messageKey(): ?string
    {
        return $this->labelKey();
    }
}
