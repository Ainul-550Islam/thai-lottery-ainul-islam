<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentWebhookStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * A persisted provider webhook envelope (evidence). Status moves
 * strictly through PaymentWebhookStatus transitions — property-set
 * after checks, never mass-assignable.
 */
class PaymentWebhook extends Model
{
    protected $fillable = [
        'webhook_key',
        'provider',
        'event_id',
        'event_type',
        'signature',
        'payload',
        'payload_fingerprint',
        'status_reason',
        'received_at',
        'verified_at',
        'claimed_at',
        'applied_at',
        'rejected_at',
        'last_error_at',
        'sightings',
        'processing_attempts',
        'metadata',
    ];

    protected $casts = [
        'status' => PaymentWebhookStatus::class,
        'payload' => 'array',
        'received_at' => 'datetime',
        'verified_at' => 'datetime',
        'claimed_at' => 'datetime',
        'applied_at' => 'datetime',
        'rejected_at' => 'datetime',
        'last_error_at' => 'datetime',
        'processing_attempts' => 'integer',
        'metadata' => 'array',
    ];
}
