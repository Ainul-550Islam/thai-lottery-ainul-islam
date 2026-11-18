<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ComplianceAttestationCode — the closed set of credentials this system is
 * able to prove.
 *
 * EACH CASE IS A CLAIM CLASS, NOT A CLAIM OUTCOME
 * 'licence' means "the operator holds a gaming licence and the evidence is on
 * file under a reference with a validity window". It does NOT mean the licence
 * is Thai, GLO-issued, or that any particular regulator approved this software.
 * ComplianceBadgeService may only ever publish the NEUTRAL label below plus the
 * attestation's reference and validity dates; any more specific sentence is a
 * forbidden claim unless the operator adds it to the evidence document itself.
 *
 * WHY 'security_audit' IS NOT 'pentest_passed'
 * An attestation records that a document exists and is valid. It cannot
 * represent the FINDINGS of that document, so the badge must not characterise
 * them. "Security audit on file" is provable; "bank-level security" is not,
 * and the second string is in the forbidden-claims deny list.
 */
enum ComplianceAttestationCode: string
{
    case Licence = 'licence';
    case SecurityAudit = 'security_audit';
    case AgePolicy = 'age_policy';
    case ResponsibleGaming = 'responsible_gaming';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The ONLY label ComplianceBadgeService is allowed to publish for a valid
     * attestation of this code.
     *
     * Deliberately factual and free of superlatives: a test scans these strings
     * against config('player_experience.compliance.forbidden_claims') and fails
     * the build if one of them ever starts asserting more than it can prove.
     */
    public function neutralLabel(): string
    {
        return match ($this) {
            self::Licence => 'Operator licence on file',
            self::SecurityAudit => 'Security audit on file',
            self::AgePolicy => '18+ age policy in force',
            self::ResponsibleGaming => 'Responsible gaming policy in force',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Licence => 'A gaming licence held by the operator, with a reference and a validity window recorded in this system.',
            self::SecurityAudit => 'An independent security assessment recorded against a document hash and a validity window.',
            self::AgePolicy => 'The operator\'s minimum-age policy, applied to every account before wagering is enabled.',
            self::ResponsibleGaming => 'The operator\'s responsible-gaming measures: limits, cool-off and self-exclusion.',
        };
    }

    /**
     * Whether a badge for this code is meaningful with no expiry date.
     *
     * A licence with no end date is still a licence the operator holds today;
     * an audit with no end date is stale by definition, so the two differ and
     * the difference is stated here rather than assumed at the call site.
     */
    public function allowsOpenEndedValidity(): bool
    {
        return match ($this) {
            self::Licence, self::AgePolicy, self::ResponsibleGaming => true,
            self::SecurityAudit => false,
        };
    }
}
