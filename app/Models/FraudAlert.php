<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FraudAlert extends Model
{
    use HasUuids;

    protected $table = 'fraud_alerts';

    protected $fillable = [
        'id',
        'idempotency_event_key',
        'event_type',
        'user_id',
        'player_user_id',
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
        'player_user_id' => 'integer',
        'amount' => 'string',
        'risk_score' => 'integer',
        'reason_codes' => 'array',
        'triggered_rules' => 'array',
        'resolved_at' => 'datetime',
    ];

    /**
     * The player this alert is about, WHEN the subject reference resolves to one.
     *
     * `user_id` is a uuid column and `users.id` is a bigint, so these are two different things:
     * the left-hand side is the identifier the detection lane stamped (which may be an external
     * reference at all), and `player_user_id` is a resolved pointer that the recorder fills in
     * only when the subject really is a numeric account id. Reading the relation therefore
     * answers "can the panel open this account?" — not "what did the lane write?".
     */
    /**
     * @return BelongsTo<User, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(User::class, 'player_user_id');
    }

    /**
     * @return HasMany<FraudCase, $this>
     */
    public function cases(): HasMany
    {
        return $this->hasMany(FraudCase::class, 'fraud_alert_id', 'id');
    }
}
