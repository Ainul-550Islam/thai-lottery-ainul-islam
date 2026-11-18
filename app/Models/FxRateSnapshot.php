<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One captured display exchange rate for a currency pair.
 *
 * DISPLAY ONLY — this is stated in the schema, in FxDisplayService and here,
 * because a table of rates sitting next to a conversion feature is exactly the
 * kind of thing a future change wires into the money path by mistake. Nothing
 * in WalletService, LedgerPostingService or WalletConversionService reads this
 * model, and Batch3OwnershipTest asserts that no new file writes to the
 * conversion ledger from an FX source.
 *
 * `rate` is decimal:12, which Laravel returns as a STRING, so bcmath can divide
 * with it exactly. A float here would reintroduce the rounding defect the whole
 * wallet lane is built to avoid.
 *
 * @property int $id
 * @property string $base
 * @property string $quote
 * @property string $rate
 * @property string $source
 * @property Carbon $captured_at
 * @property Carbon|null $expires_at
 * @property int|null $entered_by_user_id
 * @property string|null $reference
 * @property array<string, mixed>|null $meta
 */
class FxRateSnapshot extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'base',
        'quote',
        'rate',
        'source',
        'captured_at',
        'expires_at',
        'entered_by_user_id',
        'reference',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // A STRING, by design: bcmath consumes it, a float never sees it.
            'rate' => 'decimal:12',
            'captured_at' => 'datetime',
            'expires_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_user_id');
    }

    /**
     * Newest first for a normalized pair. Upper-cases both sides before
     * comparing so 'thb'/'usd' and 'THB'/'USD' are the same pair — currency
     * codes are case-insensitive in ISO 4217 and a mixed-case row must not
     * become invisible.
     */
    public function scopeForPair(Builder $query, string $base, string $quote): Builder
    {
        return $query
            ->whereRaw('UPPER(base) = ?', [strtoupper($base)])
            ->whereRaw('UPPER(quote) = ?', [strtoupper($quote)]);
    }

    /**
     * Rows captured at or before the given moment, newest first.
     *
     * `captured_at <= now` is part of the contract: a row dated in the future
     * (a typo, a clock skew, a hostile import) must never be used as "the
     * current rate", so the caller's window is applied here rather than trusted
     * to be sane.
     */
    public function scopeCapturedBefore(Builder $query, Carbon $moment): Builder
    {
        return $query->where('captured_at', '<=', $moment);
    }

    /**
     * Rows that have not been superseded and are not past their own expiry.
     */
    public function scopeUsableAt(Builder $query, Carbon $moment): Builder
    {
        return $query
            ->where('captured_at', '<=', $moment)
            ->where(function (Builder $inner) use ($moment): void {
                $inner->whereNull('expires_at')->orWhere('expires_at', '>', $moment);
            });
    }

    public function isOlderThanMinutes(int $minutes, ?Carbon $at = null): bool
    {
        return $this->captured_at->lessThan(($at ?? Carbon::now())->copy()->subMinutes($minutes));
    }
}
