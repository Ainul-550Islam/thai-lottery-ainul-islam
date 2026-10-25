<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TruemoneyRedemptionStatus;
use Database\Factories\TruemoneyVoucherRedemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A voucher custody record; raw voucher codes and URLs are never persisted. */
class TruemoneyVoucherRedemption extends Model
{
    /** @use HasFactory<TruemoneyVoucherRedemptionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'voucher_hash', 'voucher_last4', 'amount', 'currency', 'status',
        'deposit_id', 'provider_status', 'provider_payload', 'failure_code', 'client_ip',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => TruemoneyRedemptionStatus::class,
            'provider_payload' => 'array',
        ];
    }

    /** @return BelongsTo<User, self> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Deposit, self> */
    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }
}
