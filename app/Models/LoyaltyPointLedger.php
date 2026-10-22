<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyPointLedger extends Model
{
    protected $table = 'loyalty_point_ledger';

    protected $fillable = [
        'user_id',
        'entry_type',
        'points',
        'balance_after',
        'source_type',
        'source_id',
        'idempotency_key',
        'description',
        'expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'points' => 'integer',
            'balance_after' => 'integer',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
