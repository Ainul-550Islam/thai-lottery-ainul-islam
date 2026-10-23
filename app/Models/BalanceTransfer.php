<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\TransferDirection;
use App\Enums\TransferStatus;
use Database\Factories\BalanceTransferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Audit and lifecycle record for a player pocket-to-pocket balance transfer.
 */
class BalanceTransfer extends Model
{
    /** @use HasFactory<BalanceTransferFactory> */
    use HasFactory;

    public const REFERENCE_SUFFIX_LENGTH = 8;

    public const REFERENCE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected $table = 'balance_transfers';

    /**
     * Monetary, wallet, transaction-leg, reference, and lifecycle fields are
     * intentionally excluded from $fillable so they can only be set by the
     * domain services under row locks.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'direction',
        'currency',
        'fee_category',
        'idempotency_key',
        'request_ip',
        'user_agent',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => TransferDirection::class,
            'currency' => Currency::class,
            'status' => TransferStatus::class,
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'total_debited' => 'decimal:2',
            'fee_rate' => 'decimal:6',
            'source_balance_before' => 'decimal:2',
            'source_balance_after' => 'decimal:2',
            'destination_balance_before' => 'decimal:2',
            'destination_balance_after' => 'decimal:2',
            'metadata' => 'array',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $transfer): void {
            if (! is_string($transfer->uuid) || trim($transfer->uuid) === '') {
                $transfer->uuid = (string) Str::uuid();
            }

            if (! is_string($transfer->reference_number) || trim($transfer->reference_number) === '') {
                $transfer->reference_number = self::generateReference();
            }

            if ($transfer->status === null) {
                $transfer->status = TransferStatus::Pending;
            }

            if ($transfer->currency === null) {
                $transfer->currency = Currency::primary();
            }
        });
    }

    public static function generateReference(): string
    {
        $prefix = (string) config('transfer.reference_prefix', 'BT');
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'BT');
        $prefix = substr($prefix, 0, 4);
        $alphabetLength = strlen(self::REFERENCE_ALPHABET);

        for ($attempt = 0; $attempt < 16; $attempt++) {
            $suffix = '';
            for ($i = 0; $i < self::REFERENCE_SUFFIX_LENGTH; $i++) {
                $suffix .= self::REFERENCE_ALPHABET[random_int(0, $alphabetLength - 1)];
            }

            $candidate = sprintf('%s-%s', $prefix, $suffix);

            if (! self::query()->where('reference_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        $fallbackSuffix = '';
        for ($i = 0; $i < self::REFERENCE_SUFFIX_LENGTH; $i++) {
            $fallbackSuffix .= self::REFERENCE_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return sprintf('%s-%s', $prefix, $fallbackSuffix);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function sourceWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'source_wallet_id');
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function destinationWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'destination_wallet_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInDirection(Builder $query, TransferDirection|string $direction): Builder
    {
        $value = $direction instanceof TransferDirection ? $direction->value : $direction;

        return $query->where('direction', $value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithStatus(Builder $query, TransferStatus|string $status): Builder
    {
        $value = $status instanceof TransferStatus ? $status->value : $status;

        return $query->where('status', $value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->where('status', TransferStatus::Completed->value);
    }

    public function isSettled(): bool
    {
        return $this->status === TransferStatus::Completed;
    }

    public function wasFeeCharged(): bool
    {
        return bccomp($this->decimalString($this->fee_amount, '0.00'), '0.00', 2) === 1;
    }

    /**
     * Safe player-facing projection (never exposes internal IDs, IP, user-agent, or idempotency key).
     *
     * @return array<string, mixed>
     */
    public function toPlayerArray(): array
    {
        $direction = $this->direction instanceof TransferDirection
            ? $this->direction
            : TransferDirection::tryFromInput((string) $this->getRawOriginal('direction'));
        $currency = $this->currency instanceof Currency
            ? $this->currency
            : Currency::tryFrom((string) $this->getRawOriginal('currency'));
        $status = $this->status instanceof TransferStatus
            ? $this->status
            : TransferStatus::tryFromValue((string) $this->getRawOriginal('status'));
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return [
            'uuid' => (string) $this->uuid,
            'reference' => (string) $this->reference_number,
            'direction' => $direction?->value ?? (string) $this->getRawOriginal('direction'),
            'direction_label' => $direction?->label() ?? (string) $this->getRawOriginal('direction'),
            'from' => $direction?->pocketNames()['from'] ?? null,
            'to' => $direction?->pocketNames()['to'] ?? null,
            'currency' => $currency?->value ?? (string) ($this->getRawOriginal('currency') ?: 'THB'),
            'amount' => $this->decimalString($this->amount, '0.00'),
            'fee' => $this->decimalString($this->fee_amount, '0.00'),
            'total_debited' => $this->decimalString($this->total_debited, '0.00'),
            'fee_category' => $this->fee_category,
            'fee_rate' => $this->decimalRateString($this->fee_rate),
            'fee_rate_percent' => $this->feeRatePercentString($this->fee_rate),
            'fee_charged' => $this->wasFeeCharged(),
            'source_balance_before' => $this->source_balance_before !== null
                ? $this->decimalString($this->source_balance_before, '0.00')
                : null,
            'source_balance_after' => $this->source_balance_after !== null
                ? $this->decimalString($this->source_balance_after, '0.00')
                : null,
            'destination_balance_before' => $this->destination_balance_before !== null
                ? $this->decimalString($this->destination_balance_before, '0.00')
                : null,
            'destination_balance_after' => $this->destination_balance_after !== null
                ? $this->decimalString($this->destination_balance_after, '0.00')
                : null,
            'status' => $status?->value ?? (string) $this->getRawOriginal('status'),
            'status_label' => $status?->label() ?? (string) $this->getRawOriginal('status'),
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'is_reversal' => (bool) ($metadata['is_reversal'] ?? false),
            'reverses_reference' => isset($metadata['reverses_reference']) && is_string($metadata['reverses_reference'])
                ? $metadata['reverses_reference']
                : null,
            'is_reversed' => isset($metadata['reversed_by_transfer_id']) || isset($metadata['reversed_by_reference']),
            'reversed_by_reference' => isset($metadata['reversed_by_reference']) && is_string($metadata['reversed_by_reference'])
                ? $metadata['reversed_by_reference']
                : null,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Operator/audit projection including internal IDs, ledger legs, and request attribution.
     *
     * @return array<string, mixed>
     */
    public function toAuditArray(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return array_merge($this->toPlayerArray(), [
            'id' => (int) $this->id,
            'user_id' => (int) $this->user_id,
            'source_wallet_id' => $this->source_wallet_id !== null ? (int) $this->source_wallet_id : null,
            'destination_wallet_id' => $this->destination_wallet_id !== null ? (int) $this->destination_wallet_id : null,
            'debit_transaction_id' => $this->debit_transaction_id !== null ? (int) $this->debit_transaction_id : null,
            'credit_transaction_id' => $this->credit_transaction_id !== null ? (int) $this->credit_transaction_id : null,
            'fee_transaction_id' => $this->fee_transaction_id !== null ? (int) $this->fee_transaction_id : null,
            'idempotency_key' => $this->idempotency_key,
            'request_ip' => $this->request_ip,
            'user_agent' => $this->user_agent,
            'metadata' => $metadata,
            'reversal_operator_id' => isset($metadata['reversal_operator_id']) ? (int) $metadata['reversal_operator_id'] : null,
            'reversal_reason' => isset($metadata['reversal_reason']) && is_string($metadata['reversal_reason'])
                ? $metadata['reversal_reason']
                : null,
        ]);
    }

    private function decimalString(mixed $value, string $default): string
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return bcadd((string) $value, '0', 2);
    }

    private function decimalRateString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.000000';
        }

        return bcadd((string) $value, '0', 6);
    }

    private function feeRatePercentString(mixed $value): string
    {
        $rate = $this->decimalRateString($value);
        $percent = bcmul($rate, '100', 2);

        return rtrim(rtrim($percent, '0'), '.') ?: '0';
    }
}
