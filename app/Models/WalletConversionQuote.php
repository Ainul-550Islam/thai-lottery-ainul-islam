<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConversionFeeBearer;
use App\Enums\Currency;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionQuoteStatus;
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
        // The tariff that priced this quote, the channel it was asked from and the
        // hash of the request that produced it. Stored on the row because a quote is
        // an offer: what it says must be reconstructible from the row alone, without
        // the config that happened to be loaded when it was issued.
        'fee_rate',
        'channel',
        'request_fingerprint',
        'limits',
        'issued_at',
        'description',
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
            'currency' => Currency::class,
            'status' => WalletConversionQuoteStatus::class,
            'fee_bearer' => ConversionFeeBearer::class,
            'amount_in' => 'decimal:2',
            'fee_rate' => 'decimal:8',
            'limits' => 'array',
            'issued_at' => 'datetime',
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
    /**
     * Is this quote still spendable right now?
     *
     * The same three facts the open scope uses, asked of one already-loaded row:
     * open status, no spend recorded, and not past its expiry instant.
     */
    public function isOpen(): bool
    {
        $status = $this->status instanceof WalletConversionQuoteStatus
            ? $this->status
            : WalletConversionQuoteStatus::tryFrom((string) $this->status);

        return $status === WalletConversionQuoteStatus::Open
            && $this->converted_id === null
            && $this->consumed_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function scopeOpen(Builder $query): Builder
    {
        // "Open" is not only the status word. A row that has been spent carries the
        // conversion it paid for and the instant it was spent, and either of those
        // facts outranks a status column that says `open` — a half-written lifecycle
        // must never be able to re-offer a price that has already been charged.
        return $query->where('status', WalletConversionQuoteStatus::Open->value)
            ->whereNull('converted_id')
            ->whereNull('consumed_at');
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
