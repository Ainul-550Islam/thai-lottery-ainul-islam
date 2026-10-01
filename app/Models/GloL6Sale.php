<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * GLO L6 unit sales seat for one draw (product l6).
 *
 * One row per (draw_id, product) — the seat identity GLO-9 reconciliation
 * locks on. Units and money are exact: units are integers of full sale
 * units; gross is a decimal string.
 *
 * @property int $id
 * @property int $draw_id
 * @property string $product
 * @property string $seat_key
 * @property int $units_sold
 * @property int $units_full
 * @property string $gross_sales
 * @property string $ticket_price
 * @property string|null $source_reference
 * @property string $provenance
 */
class GloL6Sale extends Model
{
    protected $table = 'glo_l6_sales';

    protected $fillable = [
        'draw_id',
        'product',
        'seat_key',
        'units_sold',
        'units_full',
        'series_number',
        'gross_sales',
        'sold_fraction',
        'full_allocation_pool',
        'proportional_prize_pool',
        'currency',
        'ticket_price',
        'source_reference',
        'provenance',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'gross_sales' => 'string',
            'ticket_price' => 'string',
            'units_sold' => 'integer',
            'units_full' => 'integer',
            'series_number' => 'integer',
            'sold_fraction' => 'decimal:12',
            'full_allocation_pool' => 'decimal:2',
            'proportional_prize_pool' => 'decimal:2',
            'currency' => \App\Enums\Currency::class,
            'metadata' => 'array',
        ];
    }

    public function getGrossSalesAttribute($value): string
    {
        return $value === null ? '0.00' : bcadd((string) $value, '0.00', 2);
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public static function seatKey(int $drawId, string $product = 'l6'): string
    {
        return sprintf('%d:%s', $drawId, $product);
    }
}
