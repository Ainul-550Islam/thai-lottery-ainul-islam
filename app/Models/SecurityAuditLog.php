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
        'user_id',
        'event_type',
        'ip_address',
        'user_agent',
        'old_state',
        'new_state',
        'payload_hash',
        'signature',
        'occurred_at',
    ];

    protected $casts = [
        'old_state' => 'array',
        'new_state' => 'array',
        'occurred_at' => 'datetime',
    ];
}
