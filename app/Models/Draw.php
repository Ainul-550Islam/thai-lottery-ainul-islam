<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Draw extends Model
{
    use HasUuids;

    protected $table = 'draws';

    protected $fillable = [
        'draw_code',
        'game_type',
        'draw_date',
        'betting_starts_at',
        'betting_ends_at',
        'status',
        'total_entries_count',
        'total_pool_amount',
        'total_payout_amount',
        'version',
    ];

    protected $casts = [
        'draw_date' => 'datetime',
        'betting_starts_at' => 'datetime',
        'betting_ends_at' => 'datetime',
        'total_pool_amount' => 'string',
        'total_payout_amount' => 'string',
        'total_entries_count' => 'integer',
        'version' => 'integer',
    ];

    public function result(): HasOne
    {
        return $this->hasOne(DrawResult::class, 'draw_id', 'id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LotteryEntry::class, 'draw_id', 'id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'draw_id', 'id');
    }

    public function isOpenForBetting(): bool
    {
        $now = now();
        return $this->status === 'OPEN'
            && $now->gte($this->betting_starts_at)
            && $now->lte($this->betting_ends_at);
    }
}
