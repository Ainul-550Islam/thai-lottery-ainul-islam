<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromoCouponRedemption extends Model
{
    protected $table = 'promo_coupon_redemptions';

    protected $fillable = [
        'coupon_id',
        'user_id',
        'wallet_id',
        'financial_transaction_id',
        'idempotency_key',
        'granted_amount',
        'currency',
        'ip_address',
        'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'coupon_id' => 'integer',
            'user_id' => 'integer',
            'wallet_id' => 'integer',
            'financial_transaction_id' => 'integer',
            'granted_amount' => 'decimal:2',
            'redeemed_at' => 'datetime',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(PromoCoupon::class, 'coupon_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }
}
