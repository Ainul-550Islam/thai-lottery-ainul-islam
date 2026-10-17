<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionLedger extends Model
{
    use HasUuids;

    protected $table = 'commission_ledgers';

    protected $fillable = [
        'agent_id',
        'source_user_id',
        'lottery_entry_id',
        'draw_id',
        'hierarchy_depth',
        'basis_amount',
        'commission_rate',
        'commission_amount',
        'status',
        'ledger_transaction_id',
        'idempotency_key',
        'calculated_at',
    ];

    protected $casts = [
        'hierarchy_depth' => 'integer',
        'basis_amount' => 'string',
        'commission_rate' => 'string',
        'commission_amount' => 'string',
        'calculated_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }
}
