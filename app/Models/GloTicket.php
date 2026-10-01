<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Official GLO lottery ticket identity (L6 printed ticket / N3 companion).
 *
 * This is NOT App\Models\Ticket — that model is the operator's digital betting
 * receipt bound to a user wallet flow. A GLO ticket is identified by
 * (draw, product, six-digit number, set/series): leading zeros are preserved
 * as a digit string and never integer-cast.
 *
 * owner_user_id is optional: a physical ticket's possessor is often unknown
 * until a claim is filed.
 *
 * @property int $id
 * @property int $draw_id
 * @property string $product
 * @property string $ticket_number
 * @property string|null $set_series
 * @property int|null $owner_user_id
 * @property string $ticket_reference
 * @property array<string, mixed>|null $metadata
 */
class GloTicket extends Model
{
    protected $table = 'glo_tickets';

    protected $fillable = [
        'draw_id',
        'product',
        'ticket_number',
        'set_series',
        'owner_user_id',
        'ticket_reference',
        'metadata',
        'price',
        'currency',
        'status',
        'purchased_at',
    ];

    public function setPriceAttribute(mixed $value): void
    {
        $this->legacyMetadata['price'] = $value;
    }

    public function setCurrencyAttribute(mixed $value): void
    {
        $this->legacyMetadata['currency'] = $value instanceof \BackedEnum ? $value->value : $value;
    }

    public function setStatusAttribute(mixed $value): void
    {
        $this->legacyMetadata['status'] = $value instanceof \BackedEnum ? $value->value : $value;
    }

    public function setPurchasedAtAttribute(mixed $value): void
    {
        $this->created_at = $value;
    }

    protected array $legacyMetadata = [];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ($model->ticket_reference === null || $model->ticket_reference === '') {
                $model->ticket_reference = self::buildReference(
                    (int) $model->draw_id,
                    (string) $model->product,
                    (string) $model->ticket_number,
                    $model->set_series === null ? null : (string) $model->set_series,
                );
            }

            if ($model->legacyMetadata !== []) {
                $model->metadata = array_merge((array) $model->metadata, $model->legacyMetadata);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * Public-safe reference: {drawId}-{product}-{number} with optional series.
     * Used by the public verification endpoint (GLO-13).
     */
    public static function buildReference(int $drawId, string $product, string $ticketNumber, ?string $series = null): string
    {
        $reference = sprintf('%d-%s-%s', $drawId, $product, $ticketNumber);

        if ($series !== null && $series !== '') {
            $reference .= '-'.Str::upper($series);
        }

        return $reference;
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function freezes(): HasMany
    {
        return $this->hasMany(GloTicketFreeze::class, 'ticket_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(GloPrizeClaim::class, 'ticket_id');
    }

    public function paymentHolds(): HasMany
    {
        return $this->hasMany(GloPrizePaymentHold::class, 'ticket_id');
    }

    public function publicStatus(): HasOne
    {
        return $this->hasOne(GloPublicTicketStatus::class, 'ticket_id');
    }

    /**
     * Any freeze case currently in `frozen` that legally blocks payment.
     *
     * @return HasMany<GloTicketFreeze, $this>
     */
    public function activeFreezes()
    {
        return $this->freezes()->where('status', 'frozen');
    }
}
