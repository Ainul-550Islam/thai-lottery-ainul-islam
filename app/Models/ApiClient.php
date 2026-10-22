<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ApiClient extends Model
{
    use HasUuids;

    protected $table = 'api_clients';

    protected $fillable = [
        'id',
        'client_name',
        'api_key',
        'secret_key_hash',
        'allowed_ips',
        'rate_limit_per_minute',
        'is_active',
        'last_used_at',
    ];

    protected $casts = [
        'allowed_ips' => 'array',
        'rate_limit_per_minute' => 'integer',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = [
        'secret_key_hash',
    ];
}
