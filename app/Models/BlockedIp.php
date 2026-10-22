<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BlockedIp extends Model
{
    use HasUuids;

    protected $table = 'blocked_ips';

    protected $fillable = [
        'id',
        'ip_or_cidr',
        'ip_version',
        'is_cidr',
        'reason_code',
        'source',
        'is_active',
        'created_by_user_id',
        'unblocked_by_user_id',
        'expires_at',
        'unblocked_at',
        'notes',
    ];

    protected $casts = [
        'ip_version' => 'integer',
        'is_cidr' => 'boolean',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'unblocked_at' => 'datetime',
    ];
}
