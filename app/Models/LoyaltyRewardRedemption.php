<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRewardRedemption extends Model
{
    protected $table = 'loyalty_reward_redemptions';

    protected $fillable = [
        'user_id',
        'reward_key',
        'points_spent',
        'bonus_amount',
        'currency',
        'financial_transaction_id',
        'idempotency_key',
        'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'points_spent' => 'integer',
            'bonus_amount' => 'decimal:2',
            'financial_transaction_id' => 'integer',
            'redeemed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
