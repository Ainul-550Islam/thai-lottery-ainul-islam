<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudCase extends Model
{
    use HasUuids;

    protected $table = 'fraud_cases';

    protected $fillable = [
        'fraud_alert_id',
        'reviewer_user_id',
        'maker_user_id',
        'checker_user_id',
        'action_taken',
        'decision',
        'decision_reason',
        'metadata',
        'decided_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'decided_at' => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(FraudAlert::class, 'fraud_alert_id', 'id');
    }
}
