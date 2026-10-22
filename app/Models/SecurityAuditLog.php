<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SecurityAuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'security_audit_logs';

    protected $fillable = [
        'id',
        'user_id',
        'actor_user_id',
        'event_type',
        'ip_address',
        'client_ip',
        'user_agent',
        'severity',
        'payload',
        'old_state',
        'new_state',
        'payload_hash',
        'signature',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'old_state' => 'array',
        'new_state' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $model): void {
            if ($model->ip_address === null || $model->ip_address === '') {
                $model->ip_address = (string) ($model->client_ip ?? '0.0.0.0');
            }
            if ($model->payload_hash === null || $model->payload_hash === '') {
                $model->payload_hash = hash('sha256', json_encode([
                    'event_type' => $model->event_type,
                    'actor_user_id' => $model->actor_user_id ?? $model->user_id,
                    'payload' => $model->payload ?? $model->new_state,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
            }
        });
    }
}
