<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IdempotencyRecord extends Model
{
    use HasUuids;

    protected $table = 'idempotency_records';

    protected $fillable = [
        'id',
        'idempotency_key',
        'user_id',
        'request_path',
        'request_hash',
        'response_status',
        'response_headers',
        'response_body',
        'status',
        'locked_at',
        'completed_at',
    ];

    protected $casts = [
        'response_status' => 'integer',
        'response_headers' => 'array',
        'response_body' => 'array',
        'locked_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function isCompleted(): bool
    {
        return $this->status === 'COMPLETED';
    }
}
