<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WithdrawalRequest extends Model
{
    use HasUuids;

    protected $table = 'withdrawal_requests';

    protected $fillable = [
        'user_id',
        'withdrawal_number',
        'payout_channel',
        'account_name',
        'account_number',
        'bank_code',
        'amount',
        'fee_amount',
        'net_disbursement_amount',
        'currency',
        'status',
        'approved_by_operator_id',
        'disbursed_by_operator_id',
        'rejection_reason',
        'idempotency_key',
        'ledger_transaction_id',
        'approved_at',
        'disbursed_at',
    ];

    protected $casts = [
        'amount' => 'string',
        'fee_amount' => 'string',
        'net_disbursement_amount' => 'string',
        'approved_at' => 'datetime',
        'disbursed_at' => 'datetime',
    ];
}
