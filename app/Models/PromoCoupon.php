<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class PromoCoupon extends Model
{
    protected $table = 'promo_coupons';

    protected $fillable = [
        'code',
        'title',
        'description',
        'discount_type',
        'amount',
        'max_bonus_amount',
        'currency',
        'target_wallet_type',
        'min_deposit_total',
        'max_redemptions',
        'redemptions_count',
        'per_user_limit',
        'first_deposit_only',
        'is_active',
        'starts_at',
        'expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'max_bonus_amount' => 'decimal:2',
            'min_deposit_total' => 'decimal:2',
            'max_redemptions' => 'integer',
            'redemptions_count' => 'integer',
            'per_user_limit' => 'integer',
            'first_deposit_only' => 'boolean',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoCouponRedemption::class, 'coupon_id');
    }

    public function isCurrentlyActive(?Carbon $now = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now ??= Carbon::now();

        if ($this->starts_at !== null && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->expires_at !== null && $now->gte($this->expires_at)) {
            return false;
        }

        if ($this->max_redemptions !== null && (int) $this->redemptions_count >= (int) $this->max_redemptions) {
            return false;
        }

        return true;
    }
}
