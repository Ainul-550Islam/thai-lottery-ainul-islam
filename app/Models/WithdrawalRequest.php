<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WithdrawalRequest extends Model
{
    use HasUuids;

    protected $table = 'withdrawal_requests';

    protected $fillable = [
        'id',
        'user_id',
        'member_bank_account_id',
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

    /**
     * @return BelongsTo<MemberBankAccount, self>
     */
    public function memberBankAccount(): BelongsTo
    {
        return $this->belongsTo(MemberBankAccount::class, 'member_bank_account_id');
    }
}
