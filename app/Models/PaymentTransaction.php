<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PaymentChannel;
use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provider-facing payment fact used by the execution hub.
 *
 * It is separate from the `payments` paper used by the verified callback
 * reconciler: this row records the provider checkout/idempotency conversation,
 * while wallet mutation remains delegated to WalletService.
 */
class PaymentTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'wallet_id',
        'reference_id',
        'provider',
        'channel',
        'direction',
        'amount',
        'fee',
        'currency',
        'status',
        'processed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'channel' => PaymentChannel::class,
            'direction' => PaymentDirection::class,
            'currency' => Currency::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'processed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
