<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * GLO-13 public-safe freeze / payment-hold announcement projection.
 *
 * When a frozen ticket wins, official GLO practice allows a formal announcement
 * delaying prize payment, entered into the computer system and published on
 * the website. This table is that public projection: NO claimant national ID,
 * passport, phone, email, bank account, private evidence or internal notes.
 *
 * @property int $id
 * @property string $announcement_id
 * @property int $draw_id
 * @property int $ticket_id
 * @property int|null $freeze_id
 * @property string $ticket_reference
 * @property string $product
 * @property string|null $prize_category
 * @property string $status
 * @property Carbon|null $published_at
 * @property Carbon|null $effective_hold_at
 * @property string|null $source_authority
 * @property string $fingerprint
 */
class GloPublicTicketStatus extends Model
{
    protected $table = 'glo_public_ticket_status';

    protected $fillable = [
        'announcement_id',
        'draw_id',
        'ticket_id',
        'freeze_id',
        'ticket_reference',
        'product',
        'prize_category',
        'status',
        'published_at',
        'effective_hold_at',
        'source_authority',
        'fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'effective_hold_at' => 'datetime',
        ];
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(GloTicket::class, 'ticket_id');
    }

    public function freeze(): BelongsTo
    {
        return $this->belongsTo(GloTicketFreeze::class, 'freeze_id');
    }

    /**
     * The ONLY fields this projection may ever serialize for public consumers.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'announcement_id' => (string) $this->announcement_id,
            'ticket_reference' => (string) $this->ticket_reference,
            'product' => (string) $this->product,
            'prize_category' => $this->prize_category !== null ? (string) $this->prize_category : null,
            'status' => (string) $this->status,
            'published_at' => $this->published_at?->toIso8601String(),
            'effective_hold_at' => $this->effective_hold_at?->toIso8601String(),
            'source_authority' => $this->source_authority !== null ? (string) $this->source_authority : null,
        ];
    }
}
