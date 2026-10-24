<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class GloL6ActiveAllocation extends Model
{
    protected $table = 'glo_l6_active_allocations';

    protected $fillable = [
        'draw_id',
        'glo_l6_sale_id',
        'ticket_number',
        'set_series',
        'state',
        'owner_user_id',
        'reservation_id',
        'glo_ticket_id',
        'pricing_snapshot_hash',
        'reserved_until',
        'allocated_at',
    ];

    protected function casts(): array
    {
        return [
            'draw_id' => 'integer',
            'glo_l6_sale_id' => 'integer',
            'set_series' => 'integer',
            'owner_user_id' => 'integer',
            'reservation_id' => 'integer',
            'glo_ticket_id' => 'integer',
            'reserved_until' => 'datetime',
            'allocated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (self $allocation): void {
            if ($allocation->getOriginal('state') === 'sold') {
                throw new LogicException('Sold GLO L6 active allocation slot cannot be mutated.');
            }
        });
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(GloL6TicketReservation::class, 'reservation_id');
    }

    public function gloTicket(): BelongsTo
    {
        return $this->belongsTo(GloTicket::class, 'glo_ticket_id');
    }
}
