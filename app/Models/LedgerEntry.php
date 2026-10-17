<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'ledger_entries';

    protected $fillable = [
        'ledger_transaction_id',
        'ledger_account_id',
        'direction',
        'amount',
        'currency',
        'balance_after',
        'sequence',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'string',
        'balance_after' => 'string',
        'sequence' => 'integer',
        'created_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id', 'id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id', 'id');
    }
}
