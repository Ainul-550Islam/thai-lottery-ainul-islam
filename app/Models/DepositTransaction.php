<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DepositTransaction extends Model
{
    use HasUuids;

    protected $table = 'deposit_transactions';

    protected $fillable = [
        'user_id',
        'deposit_number',
        'gateway_provider',
        'gateway_reference_id',
        'amount',
        'fee_amount',
        'net_amount',
        'currency',
        'status',
        'idempotency_key',
        'ledger_transaction_id',
        'gateway_raw_payload',
        'completed_at',
    ];

    protected $casts = [
        'amount' => 'string',
        'fee_amount' => 'string',
        'net_amount' => 'string',
        'gateway_raw_payload' => 'array',
        'completed_at' => 'datetime',
    ];

    public function isCompleted(): bool
    {
        return $this->status === 'COMPLETED';
    }
}
