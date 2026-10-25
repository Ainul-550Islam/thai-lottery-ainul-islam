<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SlipVerificationStatus;
use Database\Factories\DepositSlipVerificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A verified slip's safe, masked facts and human-review state. */
class DepositSlipVerification extends Model
{
    /** @use HasFactory<DepositSlipVerificationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'deposit_id', 'trans_ref', 'sending_bank', 'receiving_account_masked',
        'amount', 'currency', 'slip_datetime', 'provider', 'status', 'review_reason',
        'reviewed_by', 'reviewed_at', 'upload_scan_record_id', 'provider_payload',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'slip_datetime' => 'immutable_datetime',
            'status' => SlipVerificationStatus::class,
            'reviewed_at' => 'datetime',
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

    /** @return BelongsTo<UploadScanRecord, self> */
    public function uploadScanRecord(): BelongsTo
    {
        return $this->belongsTo(UploadScanRecord::class);
    }

    /** @return BelongsTo<User, self> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
