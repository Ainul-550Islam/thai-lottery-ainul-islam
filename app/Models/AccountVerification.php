<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KycStatus;
use App\Enums\KycVerificationStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Account-facing view of the CANONICAL kyc_verifications row.
 *
 * There is ONE identity state machine (KycVerification + KycStatus).
 * This class reuses that table so the Account Verification page never
 * creates a parallel verification vocabulary. Public status words
 * (NOT_SUBMITTED / PENDING / … / APPROVED) are mapped helpers only —
 * they are never mass-assigned and never trusted from a client.
 */
class AccountVerification extends KycVerification
{
    /** Same canonical table as KycVerification — no second schema. */
    protected $table = 'kyc_verifications';

    /**
     * Map the canonical KycStatus (user aggregate) to the public
     * account-page vocabulary required by the product surface.
     */
    public static function publicStatusFromKycStatus(KycStatus $status): string
    {
        return match ($status) {
            KycStatus::Unverified => 'NOT_SUBMITTED',
            KycStatus::Pending => 'PENDING',
            KycStatus::UnderReview => 'UNDER_REVIEW',
            KycStatus::Verified => 'APPROVED',
            KycStatus::Rejected => 'REJECTED',
            KycStatus::Expired => 'EXPIRED',
        };
    }

    /**
     * Map a KycVerification decision row status to the public vocabulary.
     */
    public static function publicStatusFromVerification(KycVerificationStatus $status): string
    {
        return match ($status) {
            KycVerificationStatus::Pending => 'PENDING',
            KycVerificationStatus::UnderReview => 'UNDER_REVIEW',
            KycVerificationStatus::Verified => 'APPROVED',
            KycVerificationStatus::Rejected => 'REJECTED',
            KycVerificationStatus::Expired => 'EXPIRED',
        };
    }

    /**
     * Whether an open (non-terminal) conversation blocks a duplicate submit.
     */
    public function isOpen(): bool
    {
        $status = $this->status instanceof KycVerificationStatus
            ? $this->status
            : KycVerificationStatus::tryFrom((string) $this->status);

        return $status === KycVerificationStatus::Pending
            || $status === KycVerificationStatus::UnderReview;
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
