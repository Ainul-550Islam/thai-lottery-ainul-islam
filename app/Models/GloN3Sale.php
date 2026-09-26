<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GLO N3 seat sales row for one draw (product n3) — GLO-9 conflict gate.
 *
 * seat_state transitions: open → closed | conflicted. A GLON3_SALES_CONFLICT
 * is seated as conflicted with the gate name; it never silently rewrites
 * pool_amount. pool_amount = gross_sales × pool_rate (BCMath, service-computed).
 *
 * @property int $id
 * @property int $draw_id
 * @property string $product
 * @property string $seat_key
 * @property int $seats_sold
 * @property int $seats_full
 * @property string $gross_sales
 * @property string $pool_amount
 * @property string $ticket_price
 * @property string $seat_state
 * @property string|null $conflict_gate
 */
class GloN3Sale extends Model
{
    protected $table = 'glo_n3_sales';

    protected $fillable = [
        'draw_id',
        'product',
        'seat_key',
        'seats_sold',
        'seats_full',
        'gross_sales',
        'pool_amount',
        'ticket_price',
        'seat_state',
        'conflict_gate',
        'source_reference',
        'provenance',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'gross_sales' => 'string',
            'pool_amount' => 'string',
            'ticket_price' => 'string',
            'seats_sold' => 'integer',
            'seats_full' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function getPoolAmountAttribute($value): string
    {
        return $value === null ? '0.00' : bcadd((string) $value, '0.00', 2);
    }

    public function getGrossSalesAttribute($value): string
    {
        return $value === null ? '0.00' : bcadd((string) $value, '0.00', 2);
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public static function seatKey(int $drawId, string $product = 'n3'): string
    {
        return sprintf('%d:%s', $drawId, $product);
    }

    public function isConflicted(): bool
    {
        return $this->seat_state === 'conflicted';
    }

    public function isClosed(): bool
    {
        return $this->seat_state === 'closed';
    }
}
