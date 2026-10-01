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
        // Legacy claim intake aliases are normalized by the attribute
        // mutators below; canonical rows continue using claimant_user_id and
        // the gross_prize/stamp_duty/net_prize vocabulary.
        'user_id',
        'draw_date',
        'prize_tier',
        'claim_status',
        'gross_amount',
        'stamp_duty_amount',
        'net_payable_amount',
        'currency',
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

    public function setUserIdAttribute(mixed $value): void
    {
        $this->attributes['claimant_user_id'] = $value;
    }

    public function setPrizeTierAttribute(mixed $value): void
    {
        $this->attributes['prize_category'] = $value instanceof \BackedEnum ? $value->value : $value;
    }

    public function setClaimStatusAttribute(mixed $value): void
    {
        $this->attributes['status'] = $value instanceof \BackedEnum ? $value->value : $value;
    }

    public function setGrossAmountAttribute(mixed $value): void
    {
        $this->attributes['gross_prize'] = $value;
    }

    public function setStampDutyAmountAttribute(mixed $value): void
    {
        $this->attributes['stamp_duty'] = $value;
    }

    public function setNetPayableAmountAttribute(mixed $value): void
    {
        $this->attributes['net_prize'] = $value;
    }

    public function getClaimStatusAttribute(): string
    {
        $status = $this->getAttribute('status');

        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }

    public function getPrizeTierAttribute(): ?string
    {
        return $this->getAttribute('prize_category');
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->product ??= 'l6';
            $model->prize_category ??= $model->prize_tier ?? 'first';
            $model->claimant_user_id ??= $model->user_id;
            $model->submitted_at ??= now();
            $model->fingerprint ??= hash('sha256', (string) ($model->claim_reference ?? bin2hex(random_bytes(16))));
            $model->claim_reference ??= 'GLOCLM-'.bin2hex(random_bytes(8));

            if ($model->gross_prize === null && $model->gross_amount !== null) {
                $model->gross_prize = $model->gross_amount;
            }
            if ($model->stamp_duty === null && $model->stamp_duty_amount !== null) {
                $model->stamp_duty = $model->stamp_duty_amount;
            }
            if ($model->net_prize === null && $model->net_payable_amount !== null) {
                $model->net_prize = $model->net_payable_amount;
            }

            if ($model->ticket_id !== null && ! GloTicket::query()->whereKey($model->ticket_id)->exists()) {
                $model->ticket_id = null;
            }
            if ($model->draw_id !== null && ! Draw::query()->whereKey($model->draw_id)->exists()) {
                $model->draw_id = null;
            }
        });
    }

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
