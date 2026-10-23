<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConversionFeeBearer;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionStatus;
use App\Exceptions\Wallet\ConversionException;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    private const TWO_DECIMAL_FIELDS = [
        'amount_in',
        'fee_amount',
        'amount_out',
        'from_balance_before',
        'to_balance_before',
        'from_balance_after',
        'to_balance_after',
    ];

    private const IMMUTABLE_SETTLED_FIELDS = [
        'reference',
        'user_id',
        'direction',
        'currency',
        'from_wallet_id',
        'to_wallet_id',
        'from_wallet_type',
        'to_wallet_type',
        'amount_in',
        'fee_amount',
        'amount_out',
        'fee_rate',
        'fee_bearer',
        'fee_rule_key',
        'fee_rule_version',
        'from_balance_before',
        'to_balance_before',
        'from_balance_after',
        'to_balance_after',
        'completed_at',
        'reversal_of_id',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

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
        'fee_bearer',
        'fee_rule_key',
        'fee_rule_version',
        'from_wallet_id',
        'to_wallet_id',
        'from_wallet_type',
        'to_wallet_type',
        'from_balance_before',
        'to_balance_before',
        'from_balance_after',
        'to_balance_after',
        'channel',
        'actor_user_id',
        'quote_reference',
        'idempotency_key',
        'request_fingerprint',
        'financial_transaction_id',
        'reversal_of_id',
        'reversed_by_id',
        'reversal_reason',
        'reversed_at',
        'completed_at',
        'failure_reason',
        'metadata',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => WalletConversionDirection::class,
            'status' => WalletConversionStatus::class,
            'channel' => WalletConversionChannel::class,
            'fee_bearer' => ConversionFeeBearer::class,
            'amount_in' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'amount_out' => 'decimal:2',
            'fee_rate' => 'decimal:8',
            'from_balance_before' => 'decimal:2',
            'to_balance_before' => 'decimal:2',
            'from_balance_after' => 'decimal:2',
            'to_balance_after' => 'decimal:2',
            'reversed_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function setAttribute($key, $value)
    {
        if ($value !== null && in_array($key, self::TWO_DECIMAL_FIELDS, true)) {
            $raw = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : null);
            if ($raw === null || preg_match('/^-?\d+(?:\.\d{1,2})?$/', $raw) !== 1) {
                throw new DomainException(sprintf('Field %s must be an exact decimal with at most two fractional digits.', $key));
            }
        }

        if ($value !== null && $key === 'fee_rate') {
            $raw = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : null);
            if ($raw === null || preg_match('/^\d+(?:\.\d{1,8})?$/', $raw) !== 1) {
                throw new DomainException('Field fee_rate must be a non-negative decimal with at most eight fractional digits.');
            }
        }

        return parent::setAttribute($key, $value);
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ($model->status === null) {
                $model->status = WalletConversionStatus::Pending;
            }

            $status = $model->status instanceof WalletConversionStatus
                ? $model->status
                : WalletConversionStatus::tryFrom((string) $model->status);

            if ($status === null || in_array($status, WalletConversionStatus::settledStates(), true)) {
                throw new DomainException('A conversion cannot be created directly in a settled state.');
            }

            $direction = $model->direction instanceof WalletConversionDirection
                ? $model->direction
                : WalletConversionDirection::tryFrom((string) $model->direction);

            if ($direction !== null) {
                if (($model->attributes['from_wallet_type'] ?? null) === null || $model->attributes['from_wallet_type'] === '') {
                    $model->from_wallet_type = $direction->fromType()->value;
                }
                if (($model->attributes['to_wallet_type'] ?? null) === null || $model->attributes['to_wallet_type'] === '') {
                    $model->to_wallet_type = $direction->toType()->value;
                }
            }
        });

        static::saving(function (self $model): void {
            $direction = $model->direction instanceof WalletConversionDirection
                ? $model->direction
                : WalletConversionDirection::tryFrom((string) $model->direction);

            if ($direction === null) {
                throw new DomainException('Conversion direction is required.');
            }

            if (! $model->exists) {
                if (($model->attributes['from_wallet_type'] ?? null) === null || $model->attributes['from_wallet_type'] === '') {
                    $model->from_wallet_type = $direction->fromType()->value;
                }
                if (($model->attributes['to_wallet_type'] ?? null) === null || $model->attributes['to_wallet_type'] === '') {
                    $model->to_wallet_type = $direction->toType()->value;
                }
            }

            if ((int) $model->from_wallet_id <= 0 || (int) $model->to_wallet_id <= 0 || (int) $model->from_wallet_id === (int) $model->to_wallet_id) {
                throw new DomainException('Source and destination wallets must be distinct positive identifiers.');
            }

            if ((string) $model->from_wallet_type !== $direction->fromType()->value
                || (string) $model->to_wallet_type !== $direction->toType()->value) {
                throw new DomainException('Wallet types do not match the conversion direction.');
            }

            $amountIn = (string) ($model->attributes['amount_in'] ?? '0');
            $feeAmount = (string) ($model->attributes['fee_amount'] ?? '0');
            $amountOut = (string) ($model->attributes['amount_out'] ?? '0');

            if (bccomp($amountIn, '0', 2) <= 0 || bccomp($amountOut, '0', 2) < 0) {
                throw new DomainException('Conversion amounts must be positive.');
            }

            if ($model->reversal_of_id === null && bccomp($feeAmount, '0', 2) < 0) {
                throw new DomainException('Ordinary conversions cannot have a negative fee.');
            }

            if (bccomp(bcsub($amountIn, $feeAmount, 2), $amountOut, 2) !== 0) {
                throw new DomainException('Conversion amount_in minus fee_amount must equal amount_out.');
            }
        });

        static::updating(function (self $model): void {
            $rawOriginalStatus = (string) $model->getRawOriginal('status');
            $originalStatus = WalletConversionStatus::tryFrom($rawOriginalStatus);
            $nextStatus = $model->status instanceof WalletConversionStatus
                ? $model->status
                : WalletConversionStatus::tryFrom((string) $model->status);

            if ($originalStatus === null || $nextStatus === null) {
                throw new DomainException('Invalid conversion status.');
            }

            if ($model->isDirty('status') && $originalStatus !== $nextStatus && ! $originalStatus->canTransitionTo($nextStatus)) {
                throw new DomainException(sprintf(
                    'A conversion in state %s cannot transition to %s.',
                    $originalStatus->value,
                    $nextStatus->value,
                ));
            }

            if ($originalStatus->isSettled()) {
                foreach (self::IMMUTABLE_SETTLED_FIELDS as $field) {
                    if ($model->isDirty($field)) {
                        throw new DomainException(sprintf('Cannot modify %s on a settled conversion.', $field));
                    }
                }
            }
        });

        static::deleting(function (): void {
            throw new DomainException('Wallet conversions are immutable financial records and cannot be deleted.');
        });
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeUnsettled(Builder $query): Builder
    {
        return $query->whereIn('status', [
            WalletConversionStatus::Quoted->value,
            WalletConversionStatus::Pending->value,
            WalletConversionStatus::Processing->value,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function fromWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'from_wallet_id');
    }

    public function toWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'to_wallet_id');
    }

    public function financialTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'financial_transaction_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(WalletConversionAudit::class, 'wallet_conversion_id');
    }

    /**
     * Refuse an illegal status transition loudly. Silently accepting one would
     * rewrite the meaning of money that already moved.
     */
    public function assertTransition(WalletConversionStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
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
        $rawStatus = $this->getRawOriginal('status');
        $status = is_string($rawStatus)
            ? WalletConversionStatus::tryFrom($rawStatus)
            : (($this->attributes['status'] ?? null) instanceof WalletConversionStatus ? $this->attributes['status'] : null);

        return $status === WalletConversionStatus::Completed
            && $this->reversal_of_id === null
            && $this->reversed_by_id === null;
    }

    public function isInsideReversalWindow(int $windowHours): bool
    {
        if ($windowHours <= 0) {
            return false;
        }

        $reference = $this->completed_at;
        if (! $reference instanceof Carbon) {
            return false;
        }

        $now = Carbon::now();
        if ($reference->gt($now)) {
            return false;
        }

        return $reference->gt($now->copy()->subHours($windowHours));
    }
}
