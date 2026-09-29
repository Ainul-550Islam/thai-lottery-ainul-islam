<?php

declare(strict_types=1);

namespace App\Enums;

/*
 * PROMPT 3 — the account-verification state machine.
 *
 * "According to actual project workflow": the platform's canonical KYC
 * domain already defines the machine — Unverified / Pending /
 * UnderReview / Verified / Rejected / Expired (KycStatus +
 * KycVerificationStatus). This enum spells the SAME machine for the
 * submission-event aggregate in the public-account vocabulary:
 *
 *   NOT_SUBMITTED → PENDING → UNDER_REVIEW → APPROVED
 *   PENDING / UNDER_REVIEW → REJECTED
 *   any non-terminal → EXPIRED (retention/supersede)
 *
 * No REQUIRES_ACTION state is defined because the existing domain does
 * not have one; inventing it would create a second vocabulary (the
 * exact thing the verification facade forbids).
 *
 * Every transition is explicit: canTransitionTo() is the single
 * authority. Nothing may jump PENDING → APPROVED without passing the
 * review authorization, and no terminal state ever transitions again.
 */
enum AccountVerificationStatus: string
{
    /** No submission exists yet (public vocabulary only — rows never store this). */
    case NotSubmitted = 'not_submitted';

    case Pending = 'pending';

    case UnderReview = 'under_review';

    // Storage value mirrors the canonical KYC machine ('verified');
    // 'APPROVED' is the public WORD rendered for it.
    case Approved = 'verified';

    case Rejected = 'rejected';

    case Expired = 'expired';

    /**
     * The explicit transition table. Keys are from-states, values the
     * allowed to-states. This is the ONLY place transitions are defined.
     *
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::Pending->value => [
                self::UnderReview->value,
                self::Approved->value,
                self::Rejected->value,
                self::Expired->value,
            ],
            self::UnderReview->value => [
                self::Approved->value,
                self::Rejected->value,
                self::Expired->value,
            ],
        ];
    }

    /**
     * Whether this -> that is a legal transition.
     */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to->value, self::transitions()[$this->value] ?? [], true);
    }

    /**
     * Terminal states never transition and are never rewritten.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Approved, self::Rejected, self::Expired => true,
            self::NotSubmitted, self::Pending, self::UnderReview => false,
        };
    }

    /**
     * Open states block a duplicate concurrent submission.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Pending, self::UnderReview => true,
            self::NotSubmitted, self::Approved, self::Rejected, self::Expired => false,
        };
    }

    /**
     * The public word the member-facing pages render.
     */
    public function publicWord(): string
    {
        return match ($this) {
            self::NotSubmitted => 'NOT_SUBMITTED',
            self::Pending => 'PENDING',
            self::UnderReview => 'UNDER_REVIEW',
            self::Approved => 'APPROVED',
            self::Rejected => 'REJECTED',
            self::Expired => 'EXPIRED',
        };
    }

    /**
     * Map the canonical KYC document status onto this vocabulary — the
     * single bridge between the two words for the same fact.
     */
    public static function fromKycStatus(KycStatus $status): self
    {
        return match ($status) {
            KycStatus::Unverified => self::NotSubmitted,
            KycStatus::Pending => self::Pending,
            KycStatus::UnderReview => self::UnderReview,
            KycStatus::Verified => self::Approved,
            KycStatus::Rejected => self::Rejected,
            KycStatus::Expired => self::Expired,
        };
    }
}
