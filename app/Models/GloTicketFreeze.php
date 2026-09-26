<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GloFreezeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * GLO-11 legal/administrative ticket freeze case.
 *
 * One row = one freeze case against ONE exact ticket (never "all tickets for
 * a draw" or "all tickets of a user"). History is immutable: status moves only
 * through GloTicketFreezeService transitions that record actor + timestamp +
 * reason. Multiple cases may target the same ticket; payment is blocked while
 * ANY active legally effective freeze exists (hasActiveEffectiveFreeze), so a
 * single `ticket.frozen` flag is never the sole source of truth.
 *
 * Evidence columns hold references/hashes only — raw documents live in the
 * secure document architecture (evidence_document_id), not in this table.
 *
 * @property int $id
 * @property string $freeze_case_id
 * @property int $ticket_id
 * @property int $draw_id
 * @property string $product
 * @property string|null $ticket_number
 * @property string|null $set_series
 * @property string $requesting_authority
 * @property string $jurisdiction
 * @property string $case_reference
 * @property string|null $legal_reference_number
 * @property string $evidence_reference
 * @property string $evidence_type
 * @property string|null $evidence_document_id
 * @property string|null $evidence_content_hash
 * @property string|null $evidence_mime_type
 * @property array<string, mixed>|null $evidence_submission_metadata
 * @property Carbon|null $evidence_received_at
 * @property GloFreezeStatus $status
 * @property string $review_status
 * @property Carbon $requested_at
 * @property Carbon|null $effective_at
 * @property Carbon|null $released_at
 * @property Carbon|null $expiry_at
 * @property Carbon|null $expired_at
 * @property int|null $reviewer_id
 * @property string|null $resolution_reason
 * @property string $fingerprint
 * @property int|null $requested_by
 * @property array<string, mixed>|null $audit_metadata
 */
class GloTicketFreeze extends Model
{
    protected $fillable = [
        'freeze_case_id',
        'ticket_id',
        'draw_id',
        'product',
        'ticket_number',
        'set_series',
        'requesting_authority',
        'jurisdiction',
        'case_reference',
        'legal_reference_number',
        'evidence_reference',
        'evidence_type',
        'evidence_document_id',
        'evidence_content_hash',
        'evidence_mime_type',
        'evidence_submission_metadata',
        'evidence_received_at',
        'status',
        'review_status',
        'requested_at',
        'effective_at',
        'released_at',
        'expiry_at',
        'expired_at',
        'reviewer_id',
        'resolution_reason',
        'fingerprint',
        'requested_by',
        'audit_metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => GloFreezeStatus::class,
            'evidence_received_at' => 'datetime',
            'requested_at' => 'datetime',
            'effective_at' => 'datetime',
            'released_at' => 'datetime',
            'expiry_at' => 'datetime',
            'expired_at' => 'datetime',
            'evidence_submission_metadata' => 'array',
            'audit_metadata' => 'array',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(GloTicket::class, 'ticket_id');
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function paymentHolds(): HasMany
    {
        return $this->hasMany(GloPrizePaymentHold::class, 'freeze_id');
    }

    public function activePaymentHold(): HasOne
    {
        return $this->hasOne(GloPrizePaymentHold::class, 'freeze_id')
            ->where('status', 'active');
    }

    /**
     * Whether this case currently blocks payment (status frozen and not past
     * an unexpired deadline that has already been transitioned to expired).
     */
    public function isEffectivelyFrozen(): bool
    {
        if ($this->status !== GloFreezeStatus::Frozen) {
            return false;
        }

        if ($this->expiry_at !== null && $this->expiry_at->isPast() && $this->expired_at !== null) {
            return false;
        }

        return true;
    }
}
