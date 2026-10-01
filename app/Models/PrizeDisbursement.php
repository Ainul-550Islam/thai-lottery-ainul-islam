<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrizeDisbursementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrizeDisbursement extends Model
{
    protected $fillable = [
        'disbursement_key',
        'payout_id',
        // Legacy settlement intake fields are normalized into the canonical
        // disbursement fields in the creating hook below.
        'user_id',
        'draw_id',
        'prize_amount',
        'reference_number',
        'status',
        'payout_batch_id',
        'amount',
        'currency',
        'settlement_fingerprint',
        'reserved_at',
        'disbursed_at',
        'reversed_at',
        'failed_at',
        'failure_reason',
        'reversal_reason',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $reference = (string) ($model->reference_number ?? 'DISB-'.bin2hex(random_bytes(12)));
            $model->reference_number = $reference;
            $model->disbursement_key ??= $reference;
            $model->settlement_fingerprint ??= hash('sha256', $reference);

            if ($model->amount === null && $model->prize_amount !== null) {
                $model->amount = $model->prize_amount;
            }

            // A legacy notification-only record may not yet have a payout
            // draw row. Preserve the nullable canonical relation instead of
            // violating the database foreign key with an invented draw.
            if ($model->draw_id !== null && ! Draw::query()->whereKey($model->draw_id)->exists()) {
                $model->draw_id = null;
            }
        });
    }

    protected $casts = [
        'status' => PrizeDisbursementStatus::class,
        'amount' => 'decimal:2',
        'reserved_at' => 'datetime',
        'disbursed_at' => 'datetime',
        'reversed_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }
}
