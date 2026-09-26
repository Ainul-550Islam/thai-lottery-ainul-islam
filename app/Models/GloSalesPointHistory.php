<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only daily sales-location history (GLO-16).
 * One row per (dealer, effective_date) — historical overwrite is refused.
 *
 * @property int $id
 * @property int $sales_point_id
 * @property int|null $dealer_id
 * @property string $effective_date
 * @property string $source
 * @property string $source_state
 */
class GloSalesPointHistory extends Model
{
    protected $table = 'glo_sales_point_history';

    protected $fillable = [
        'sales_point_id',
        'dealer_id',
        'display_name',
        'address',
        'province',
        'district',
        'subdistrict',
        'latitude',
        'longitude',
        'effective_date',
        'source',
        'source_state',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'string',
            'longitude' => 'string',
            'effective_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function salesPoint(): BelongsTo
    {
        return $this->belongsTo(GloSalesPoint::class, 'sales_point_id');
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(GloDealer::class, 'dealer_id');
    }
}
