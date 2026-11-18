<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * SecurityCheckKey — the closed set of verifiable predicates behind the
 * security score.
 *
 * EVERY KEY MAPS TO A REAL COLUMN OR A REAL QUERY
 *   two_factor              users.totp_credentials row active + confirmed
 *   email_verified          users.email_verified_at is not null
 *   phone_verified          users.phone_verified_at is not null
 *   kyc_verified            users.kyc_status is KycStatus::Approved
 *   no_suspicious_activity  no unresolved SecurityEvent inside the configured
 *                           window
 *
 * None of these is a vanity metric: each one is answerable with a single
 * indexed read, and SecurityOverviewService is forbidden from returning a
 * check whose answer it did not read. A check that cannot be answered (for
 * example, the KYC table missing) is reported as FAILED, never as passed by
 * default — fail-closed is the only defensible direction for a security score.
 *
 * The weight of each key lives in config/player_experience.php so policy can
 * change without a deployment, and the sum of weights is asserted to be 100 by
 * SecurityScoreConfigTest.
 */
enum SecurityCheckKey: string
{
    case TwoFactor = 'two_factor';
    case EmailVerified = 'email_verified';
    case PhoneVerified = 'phone_verified';
    case KycVerified = 'kyc_verified';
    case NoSuspiciousActivity = 'no_suspicious_activity';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The configured weight of this check, as a plain integer.
     *
     * Read through the enum so no caller can invent a weight of its own and no
     * two call sites can disagree about the denominator.
     */
    public function weight(): int
    {
        /** @var array<string, int|string> $weights */
        $weights = (array) config('player_experience.security.score_weights', []);

        return (int) ($weights[$this->value] ?? 0);
    }

    public function label(): string
    {
        return match ($this) {
            self::TwoFactor => 'Two-factor authentication',
            self::EmailVerified => 'Email verified',
            self::PhoneVerified => 'Phone verified',
            self::KycVerified => 'Identity verified',
            self::NoSuspiciousActivity => 'No unresolved security alerts',
        };
    }

    /**
     * A short, neutral explanation used by the member-facing overview. It
     * states what the check IS, never that the account is "safe".
     */
    public function hint(): string
    {
        return match ($this) {
            self::TwoFactor => 'Sign-in codes from an authenticator app.',
            self::EmailVerified => 'An email address confirmed by its owner.',
            self::PhoneVerified => 'A phone number confirmed by its owner.',
            self::KycVerified => 'An identity document reviewed and approved.',
            self::NoSuspiciousActivity => 'No unresolved alerts in the recent window.',
        };
    }

    /**
     * The action a member can take to satisfy a failed check, used by the UI.
     * A check with no self-service path returns null rather than a dead link.
     */
    public function remediationPath(): ?string
    {
        return match ($this) {
            self::TwoFactor => '/player/security/2fa/setup',
            self::EmailVerified => '/account/verification',
            self::PhoneVerified => '/account/verification',
            self::KycVerified => '/account/verification',
            self::NoSuspiciousActivity => null,
        };
    }
}
