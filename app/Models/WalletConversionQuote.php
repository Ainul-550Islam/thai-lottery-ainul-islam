<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConversionFeeBearer;
use App\Enums\WalletConversionDirection;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
    private const TWO_DECIMAL_FIELDS = [
        'amount_in',
        'fee_amount',
        'amount_out',
    ];

    private const IMMUTABLE_TERMS = [
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
        'limits',
        'issued_at',
        'expires_at',
    ];

    protected $table = 'wallet_conversion_quotes';

    protected $attributes = [
        'status' => 'open',
    ];

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
        'limits',
        'status',
        'idempotency_key',
        'issued_at',
        'expires_at',
        'converted_id',
        'consumed_at',
        'description',
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
            'rate' => 'decimal:8',
            'limits' => 'array',
            'issued_at' => 'datetime',
            'consumed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function setAttribute($key, $value)
    {
        if ($value !== null && in_array($key, self::TWO_DECIMAL_FIELDS, true)) {
            $raw = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : null);
            if ($raw === null || preg_match('/^\d+(?:\.\d{1,2})?$/', $raw) !== 1) {
                throw new DomainException(sprintf('Quote field %s must be a non-negative decimal with at most two fractional digits.', $key));
            }
        }

        if ($value !== null && $key === 'rate') {
            $raw = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : null);
            if ($raw === null || preg_match('/^\d+(?:\.\d{1,8})?$/', $raw) !== 1) {
                throw new DomainException('Quote rate must be a non-negative decimal with at most eight fractional digits.');
            }
        }

        return parent::setAttribute($key, $value);
    }

    protected static function booted(): void
    {
        static::creating(function (self $quote): void {
            $quote->status ??= 'open';
            $quote->issued_at ??= Carbon::now();
        });

        static::saving(function (self $quote): void {
            $amountIn = (string) ($quote->attributes['amount_in'] ?? '0');
            $feeAmount = (string) ($quote->attributes['fee_amount'] ?? '0');
            $amountOut = (string) ($quote->attributes['amount_out'] ?? '0');

            if (bccomp($amountIn, '0', 2) <= 0 || bccomp($feeAmount, '0', 2) < 0 || bccomp($amountOut, '0', 2) < 0) {
                throw new DomainException('Quote monetary values must be non-negative.');
            }

            if (bccomp(bcsub($amountIn, $feeAmount, 2), $amountOut, 2) !== 0) {
                throw new DomainException('Quote amount_in minus fee_amount must equal amount_out.');
            }
        });

        static::updating(function (self $quote): void {
            foreach (self::IMMUTABLE_TERMS as $field) {
                if ($quote->isDirty($field)) {
                    throw new DomainException(sprintf('Quote field %s is immutable.', $field));
                }
            }

            $originalStatus = (string) $quote->getRawOriginal('status');
            $nextStatus = (string) $quote->status;

            if ($originalStatus !== 'open') {
                throw new DomainException('A closed quote cannot be reopened or modified.');
            }

            if (! in_array($nextStatus, ['open', 'consumed', 'expired'], true)) {
                throw new DomainException(sprintf('Invalid quote status %s.', $nextStatus));
            }

            if ($nextStatus === 'consumed') {
                if ($quote->isExpired()) {
                    throw new DomainException('An expired quote cannot be consumed.');
                }
                if ($quote->converted_id === null || $quote->consumed_at === null) {
                    throw new DomainException('Consuming a quote requires converted_id and consumed_at.');
                }
            }
        });

        static::deleting(function (): void {
            throw new DomainException('Wallet conversion quotes are immutable and cannot be deleted.');
        });
    }

    /**
     * Still spendable: not consumed and not expired. This is the question the
     * quote itself answers; whether its clock has run out is a separate check
     * so the sweep can relabel rows exactly once.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open')
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
        if (! $this->expires_at instanceof Carbon) {
            return false;
        }

        return $this->expires_at->lte(Carbon::now());
    }

    public function isOpen(): bool
    {
        return (string) $this->status === 'open'
            && $this->converted_id === null
            && $this->consumed_at === null
            && ! $this->isExpired();
    }
}
