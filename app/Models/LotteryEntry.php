<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LotteryEntry extends Model
{
    use HasUuids;

    protected $table = 'lottery_entries';

    protected $fillable = [
        'draw_id',
        'user_id',
        'agent_id',
        'voucher_number',
        'ticket_number',
        'bet_type',
        'stake_amount',
        'multiplier',
        'potential_win_amount',
        'actual_win_amount',
        'status',
        'idempotency_key',
        'chunk_index',
        'placed_at',
    ];

    protected $casts = [
        'stake_amount' => 'string',
        'multiplier' => 'string',
        'potential_win_amount' => 'string',
        'actual_win_amount' => 'string',
        'chunk_index' => 'integer',
        'placed_at' => 'datetime',
    ];

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class, 'draw_id', 'id');
    }

    public function payout(): HasOne
    {
        return $this->hasOne(Payout::class, 'lottery_entry_id', 'id');
    }
}
