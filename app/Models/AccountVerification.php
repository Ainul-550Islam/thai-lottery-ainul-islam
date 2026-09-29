<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountVerificationStatus;
use App\Enums\KycStatus;
use App\Enums\KycVerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/*
 * PROMPT 3 — the account-verification SUBMISSION-EVENT aggregate.
 *
 * WHAT THIS IS: one immutable row per verification submission — who
 * submitted, when, which country/mobile pair, which document pair,
 * and which review decision closed it. It is the auditable history
 * the member page and the reviewer actions read.
 *
 * WHAT THIS IS NOT: a second identity state machine. The canonical
 * identity state stays kyc_verifications / kyc_documents exactly where
 * it has always been; the public status words still map through the
 * static helpers below, and submissions keep flowing through the
 * hardened KYC storage/review stack.
 *
 * IMMUTABILITY: rows are append-only. A retry after rejection is a
 * NEW row; an approved historical row is never rewritten into
 * rejected. The fingerprint column is UNIQUE — the same logical
 * submission cannot be attached twice.
 */
class AccountVerification extends Model
{
    use SoftDeletes;

    /**
     * The submission-event table (PROMPT 3 migration 2026_09_28_230001).
     * Previously this class was a read-through view of kyc_verifications;
     * the static mapping helpers below keep that contract for every
     * existing caller.
     */
    protected $table = 'account_verifications';

    protected $fillable = [
        'verification_reference',
        'user_id',
        'status',
        'country_code',
        'mobile',
        'document_type',
        'front_document_id',
        'back_document_id',
        'submitted_at',
        'processed_at',
        'reviewer_id',
        'review_reason',
        'rule_version',
        'fingerprint',
        'metadata',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'status' => AccountVerificationStatus::class,
        'submitted_at' => 'datetime',
        'processed_at' => 'datetime',
        'metadata' => 'array',
    ];

    /*
    |----------------------------------------------------------------------
    | Legacy mapping helpers (unchanged public contract)
    |----------------------------------------------------------------------
    */

    /**
     * Map the canonical KycStatus (user aggregate) to the public
     * account-page vocabulary required by the product surface.
     */
    public static function publicStatusFromKycStatus(KycStatus $status): string
    {
        return AccountVerificationStatus::fromKycStatus($status)->publicWord();
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

    /*
    |----------------------------------------------------------------------
    | Aggregate behaviour
    |----------------------------------------------------------------------
    */

    /**
     * Whether this (non-terminal) submission blocks a duplicate submit.
     */
    public function isOpen(): bool
    {
        $status = $this->status instanceof AccountVerificationStatus
            ? $this->status
            : AccountVerificationStatus::tryFrom((string) $this->status);

        return $status !== null && $status->isOpen();
    }

    /**
     * Terminal submissions never change and never block a retry.
     */
    public function isTerminal(): bool
    {
        $status = $this->status instanceof AccountVerificationStatus
            ? $this->status
            : AccountVerificationStatus::tryFrom((string) $this->status);

        return $status === null || $status->isTerminal();
    }

    /**
     * The member-safe projection. NEVER includes storage paths,
     * internal disk names or reviewer-internal data.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'reference' => (string) $this->verification_reference,
            'status' => $this->status instanceof AccountVerificationStatus
                ? $this->status->publicWord()
                : (string) $this->status,
            'document_type' => (string) $this->document_type,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
        ];
    }

    /*
    |----------------------------------------------------------------------
    | Scopes & relations
    |----------------------------------------------------------------------
    */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AccountVerificationStatus::Pending->value,
            AccountVerificationStatus::UnderReview->value,
        ]);
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<KycDocument, self>
     */
    public function frontDocument(): BelongsTo
    {
        return $this->belongsTo(KycDocument::class, 'front_document_id');
    }

    /**
     * @return BelongsTo<KycDocument, self>
     */
    public function backDocument(): BelongsTo
    {
        return $this->belongsTo(KycDocument::class, 'back_document_id');
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
