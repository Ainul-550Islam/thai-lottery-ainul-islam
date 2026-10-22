<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LedgerTransaction extends Model
{
    use HasUuids;

    protected $table = 'ledger_transactions';

    protected $fillable = [
        'id',
        'transaction_number',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'currency',
        'total_amount',
        'description',
        'status',
        'previous_hash',
        'hash',
        'posted_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:4',
        'posted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $tx): void {
            if (empty($tx->idempotency_key)) {
                $tx->idempotency_key = 'LTX-IDEMP-'.($tx->transaction_number ?: (string) Str::uuid());
            }
            if ($tx->total_amount === null || $tx->total_amount === '') {
                $tx->total_amount = '0.0000';
            }
            if (empty($tx->hash)) {
                $tx->hash = hash('sha256', ($tx->previous_hash ?? 'GENESIS').':'.($tx->transaction_number ?? '').':'.$tx->total_amount);
            }
        });
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'ledger_transaction_id', 'id')->orderBy('sequence');
    }
}
