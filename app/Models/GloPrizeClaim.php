<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GloClaimChannel;
use App\Enums\GloClaimStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * GLO-12 prize claim against an official GLO ticket win.
 *
 * Distinct from the operator PrizeClaim lane (payouts.metadata.claim): this
 * row owns GLO-specific money (gross / stamp duty / net), freeze interaction,
 * age+identity evidence stamps and payment-hold linkage.
 *
 * All money fields are decimal strings computed server-side. Client-supplied
 * age, status, gross, duty, net, is_frozen and payment_hold are never trusted.
 *
 * @property int $id
 * @property string $claim_reference
 * @property int $ticket_id
 * @property int $draw_id
 * @property string $product
 * @property string $prize_category
 * @property string|null $ticket_number
 * @property string $gross_prize
 * @property string $stamp_duty
 * @property string $net_prize
 * @property int $claimant_user_id
 * @property string|null $identity_reference
 * @property string $age_verification_result
 * @property Carbon|null $age_verified_at
 * @property int|null $verified_age_years
 * @property bool $original_ticket_evidenced
 * @property bool $identity_document_evidenced
 * @property GloClaimChannel $claim_channel
 * @property GloClaimStatus $status
 * @property string $payment_status
 * @property string $hold_status
 * @property Carbon $submitted_at
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $paid_at
 * @property string|null $payment_transaction_reference
 * @property string|null $hold_reason
 * @property string $fingerprint
 */
class GloPrizeClaim extends Model
{
    protected $fillable = [
        'claim_reference',
        'ticket_id',
        'draw_id',
        'product',
        'prize_category',
        'ticket_number',
        'gross_prize',
        'stamp_duty',
        'net_prize',
        'claimant_user_id',
        'identity_reference',
        'age_verification_result',
        'age_verified_at',
        'verified_age_years',
        'original_ticket_evidenced',
        'identity_document_evidenced',
        'claim_channel',
        'status',
        'payment_status',
        'hold_status',
        'submitted_at',
        'reviewed_at',
        'approved_at',
        'paid_at',
        'reviewed_by',
        'paid_by',
        'payment_transaction_reference',
        'rejection_reason',
        'hold_reason',
        'fingerprint',
        'audit_metadata',
    ];

    protected function casts(): array
    {
        return [
            'gross_prize' => 'string',
            'stamp_duty' => 'string',
            'net_prize' => 'string',
            'claim_channel' => GloClaimChannel::class,
            'status' => GloClaimStatus::class,
            'age_verified_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'original_ticket_evidenced' => 'boolean',
            'identity_document_evidenced' => 'boolean',
            'verified_age_years' => 'integer',
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

    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimant_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function paymentHolds(): HasMany
    {
        return $this->hasMany(GloPrizePaymentHold::class, 'claim_id');
    }

    public function activePaymentHold(): HasOne
    {
        return $this->hasOne(GloPrizePaymentHold::class, 'claim_id')
            ->where('status', 'active');
    }

    public function isPaid(): bool
    {
        return $this->status === GloClaimStatus::Paid
            && $this->payment_transaction_reference !== null;
    }

    /**
     * Money getters always normalize to 2-decimal strings so a driver that
     * strips trailing zeros ("30000") never leaks into API payloads or math.
     */
    public function getGrossPrizeAttribute($value): string
    {
        return bcadd((string) $value, '0.00', 2);
    }

    public function getStampDutyAttribute($value): string
    {
        return bcadd((string) $value, '0.00', 2);
    }

    public function getNetPrizeAttribute($value): string
    {
        return bcadd((string) $value, '0.00', 2);
    }
}
