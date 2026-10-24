<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConversionFeeBearer;
use App\Enums\WalletConversionDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A priced promise with an expiry.
 *
 * The quote is the exact numbers a member saw: amount in, fee, amount out and
 * the tariff version that produced them. Executing against it later charges
 * THOSE numbers — never a re-priced guess — or refuses with quote_expired when
 * the clock has run out. A quote moves no money and posts nothing.
 */
class WalletConversionQuote extends Model
{
    protected $table = 'wallet_conversion_quotes';

    protected $fillable = [
        'reference',
        'user_id',
        'direction',
        'currency',
        'amount_in',
        'fee_amount',
        'amount_out',
        'rate',
        'fee_bearer',
        'fee_rule_key',
        'fee_rule_version',
        'status',
        'idempotency_key',
        'converted_id',
        'consumed_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => WalletConversionDirection::class,
            'fee_bearer' => ConversionFeeBearer::class,
            'amount_in' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'amount_out' => 'decimal:2',
            'consumed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Still spendable: not consumed and not expired. This is the question the
     * quote itself answers; whether its clock has run out is a separate check
     * so the sweep can relabel rows exactly once.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversion(): BelongsTo
    {
        return $this->belongsTo(WalletConversion::class, 'converted_id');
    }

    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast();
    }
}
