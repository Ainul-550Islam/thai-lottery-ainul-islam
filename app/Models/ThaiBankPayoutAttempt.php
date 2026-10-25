<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ThaiBankPayoutState;
use Database\Factories\ThaiBankPayoutAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One immutable external-dispatch identity per withdrawal reference. */
class ThaiBankPayoutAttempt extends Model
{
    /** @use HasFactory<ThaiBankPayoutAttemptFactory> */
    use HasFactory;

    protected $fillable = [
        'withdrawal_id', 'withdrawal_reference', 'bank', 'amount', 'destination_type',
        'destination_masked', 'state', 'provider_reference', 'request_fingerprint',
        'outcome_unknown', 'last_polled_at', 'raw_response',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'state' => ThaiBankPayoutState::class,
            'outcome_unknown' => 'boolean',
            'last_polled_at' => 'datetime',
            'raw_response' => 'array',
        ];
    }

    /** @return BelongsTo<Withdrawal, self> */
    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(Withdrawal::class);
    }
}
