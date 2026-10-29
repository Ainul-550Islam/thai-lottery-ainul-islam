<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\WalletConversionStatusCast;
use App\Enums\ConversionFeeBearer;
use App\Enums\Currency;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionStatus;
use App\Exceptions\Wallet\ConversionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One movement between the Cash and Win wallets.
 *
 * The row is created before any money moves and completed inside the same
 * database transaction that posted its ledger entries, so the books and the
 * movement can never disagree about whether it happened. A reversal never
 * mutates a completed row's economics: it posts the opposite movement as a NEW
 * row and links the two through reversal_of_id / reversed_by_id.
 */
class WalletConversion extends Model
{
    protected $fillable = [
        'reference',
        'user_id',
        'direction',
        'status',
        'currency',
        'amount_in',
        'fee_amount',
        'amount_out',
        'fee_rate',
        'fee_rule_key',
        'fee_rule_version',
        'from_wallet_id',
        'to_wallet_id',
        // The lane's services name the two sides by their ROLE in the movement — the
        // source that is debited and the destination that is credited — while the
        // columns are named from_/to_. Both vocabularies are accepted and mapped onto
        // the same columns by the accessors below: one set of columns, one meaning.
        'source_wallet_id',
        'destination_wallet_id',
        'source_balance_after',
        'destination_balance_after',
        'reason',
        'from_balance_before',
        'to_balance_before',
        'fee_bearer',
        'actor_user_id',
        'request_fingerprint',
        'metadata',
        'from_wallet_type',
        'to_wallet_type',
        'from_balance_after',
        'to_balance_after',
        'channel',
        'quote_reference',
        'idempotency_key',
        'financial_transaction_id',
        'reversal_of_id',
        'reversed_by_id',
        'reversal_reason',
        'reversed_at',
        'completed_at',
        'failure_reason',
        'note',
    ];

    /**
     * `source_wallet_id` is `from_wallet_id`.
     */
    public function setSourceWalletIdAttribute(mixed $value): void
    {
        $this->attributes['from_wallet_id'] = $value;
    }

    public function getSourceWalletIdAttribute(): mixed
    {
        return $this->attributes['from_wallet_id'] ?? null;
    }

    /**
     * `destination_wallet_id` is `to_wallet_id`.
     */
    public function setDestinationWalletIdAttribute(mixed $value): void
    {
        $this->attributes['to_wallet_id'] = $value;
    }

    public function getDestinationWalletIdAttribute(): mixed
    {
        return $this->attributes['to_wallet_id'] ?? null;
    }

    /**
     * `source_balance_after` is `from_balance_after`.
     */
    public function setSourceBalanceAfterAttribute(mixed $value): void
    {
        $this->attributes['from_balance_after'] = $value;
    }

    public function getSourceBalanceAfterAttribute(): mixed
    {
        return $this->attributes['from_balance_after'] ?? null;
    }

    /**
     * `destination_balance_after` is `to_balance_after`.
     */
    public function setDestinationBalanceAfterAttribute(mixed $value): void
    {
        $this->attributes['to_balance_after'] = $value;
    }

    public function getDestinationBalanceAfterAttribute(): mixed
    {
        return $this->attributes['to_balance_after'] ?? null;
    }

    /**
     * `reason` is `reversal_reason`: the column records why an original was
     * reversed, and it lives on the reversal row.
     */
    public function setReasonAttribute(mixed $value): void
    {
        $this->attributes['reversal_reason'] = $value;
    }

    public function getReasonAttribute(): ?string
    {
        return $this->attributes['reversal_reason'] ?? null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => WalletConversionDirection::class,
            // The stored currency is a three-letter code; it is exposed as the Currency
            // value object so every caller compares and formats it the same way.
            'currency' => Currency::class,
            'fee_bearer' => ConversionFeeBearer::class,
            'metadata' => 'array',
            'status' => WalletConversionStatusCast::class,
            'channel' => WalletConversionChannel::class,
            'amount_in' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'amount_out' => 'decimal:2',
            'from_balance_before' => 'decimal:2',
            'to_balance_before' => 'decimal:2',
            'from_balance_after' => 'decimal:2',
            'to_balance_after' => 'decimal:2',
            'reversed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'from_wallet_id');
    }

    public function toWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'to_wallet_id');
    }

    /**
     * Refuse an illegal status transition loudly. Silently accepting one would
     * rewrite the meaning of money that already moved.
     */
    public function assertTransition(WalletConversionStatus $target): void
    {
        if (! in_array($target, $this->status->allowedTransitions(), true)) {
            throw ConversionException::failure(
                'wallet_conversion_failed',
                sprintf('A conversion in state %s cannot move to %s.', $this->status->value, $target->value),
                ['reference' => (string) $this->reference, 'from' => $this->status->value, 'to' => $target->value],
            );
        }
    }

    /**
     * Reversible means: money moved, and no reversal has undone it yet. The
     * window question is separate — see isInsideReversalWindow().
     */
    public function canBeReversed(): bool
    {
        return $this->status === WalletConversionStatus::Completed
            && $this->reversal_of_id === null
            && $this->reversed_by_id === null;
    }

    public function isInsideReversalWindow(int $windowHours): bool
    {
        $completed = $this->completed_at;
        if ($windowHours <= 0 || $completed === null) {
            return false;
        }

        $now = Carbon::now();

        // A future completion is not evidence that money already moved; the
        // exact expiry instant is outside the window, as with quote expiry.
        return $completed->lessThanOrEqualTo($now)
            && $completed->greaterThan($now->copy()->subHours($windowHours));
    }
}
