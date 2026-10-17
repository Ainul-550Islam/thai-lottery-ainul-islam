<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    use HasUuids;

    protected $table = 'payouts';

    protected $fillable = [
        'draw_id',
        'lottery_entry_id',
        'user_id',
        'payout_number',
        'winning_tier',
        'gross_payout_amount',
        'tax_withheld',
        'net_payout_amount',
        'status',
        'payout_idempotency_key',
        'ledger_transaction_id',
        'settled_at',
    ];

    protected $casts = [
        'gross_payout_amount' => 'string',
        'tax_withheld' => 'string',
        'net_payout_amount' => 'string',
        'settled_at' => 'datetime',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(LotteryEntry::class, 'lottery_entry_id', 'id');
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class, 'draw_id', 'id');
    }
}
