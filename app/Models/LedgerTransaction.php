<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerTransaction extends Model
{
    use HasUuids;

    protected $table = 'ledger_transactions';

    protected $fillable = [
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
        'total_amount' => 'string',
        'posted_at' => 'datetime',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'ledger_transaction_id', 'id')->orderBy('sequence');
    }
}
