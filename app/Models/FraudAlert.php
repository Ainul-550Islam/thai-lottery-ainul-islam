<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FraudAlert extends Model
{
    use HasUuids;

    protected $table = 'fraud_alerts';

    protected $fillable = [
        'idempotency_event_key',
        'event_type',
        'user_id',
        'amount',
        'currency',
        'risk_score',
        'decision',
        'reason_codes',
        'triggered_rules',
        'status',
        'assigned_to_user_id',
        'resolved_by_user_id',
        'resolution_notes',
        'resolved_at',
    ];

    protected $casts = [
        'amount' => 'string',
        'risk_score' => 'integer',
        'reason_codes' => 'array',
        'triggered_rules' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function cases(): HasMany
    {
        return $this->hasMany(FraudCase::class, 'fraud_alert_id', 'id');
    }
}
