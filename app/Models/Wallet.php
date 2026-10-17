<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class Wallet
 *
 * Immutable fillable attributes - balance is NEVER directly assigned.
 */
class Wallet extends Model
{
    use HasUuids;

    protected $table = 'wallets';

    /**
     * Balances are deliberately excluded from mass-assignment for financial integrity.
     */
    protected $fillable = [
        'user_id',
        'type',
        'currency',
        'status',
    ];

    protected $casts = [
        'balance' => 'string',
        'locked_balance' => 'string',
        'version' => 'integer',
    ];

    public function getAvailableBalanceAttribute(): string
    {
        return bcsub($this->balance, $this->locked_balance, 4);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
