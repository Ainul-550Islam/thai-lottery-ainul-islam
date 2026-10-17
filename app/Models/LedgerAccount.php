<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    use HasUuids;

    protected $table = 'ledger_accounts';

    protected $fillable = [
        'account_number',
        'name',
        'type',
        'currency',
        'current_balance',
        'user_id',
        'is_active',
    ];

    protected $casts = [
        'current_balance' => 'string',
        'is_active' => 'boolean',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'ledger_account_id', 'id');
    }
}
