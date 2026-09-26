<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One sales-reconciliation run for (draw, product) — GLO-9.
 *
 * status: matched | variance | conflicted.
 * conflicted carries conflict_gate = GLON3_SALES_CONFLICT and blocks automatic
 * settlement of that seat until an operator clears the gate with evidence.
 *
 * @property int $id
 * @property string $reconciliation_reference
 * @property int $draw_id
 * @property string $product
 * @property int $expected_seats
 * @property int $recorded_seats
 * @property string $expected_gross
 * @property string $recorded_gross
 * @property string $variance_gross
 * @property string $status
 * @property string|null $conflict_gate
 * @property Carbon|null $reconciled_at
 */
class GloSalesReconciliation extends Model
{
    protected $table = 'glo_sales_reconciliations';

    protected $fillable = [
        'reconciliation_reference',
        'draw_id',
        'product',
        'expected_seats',
        'recorded_seats',
        'expected_gross',
        'recorded_gross',
        'variance_gross',
        'status',
        'conflict_gate',
        'details',
        'reconciled_by',
        'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_gross' => 'string',
            'recorded_gross' => 'string',
            'variance_gross' => 'string',
            'details' => 'array',
            'reconciled_at' => 'datetime',
            'expected_seats' => 'integer',
            'recorded_seats' => 'integer',
        ];
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
