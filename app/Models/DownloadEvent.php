<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DownloadEvent extends Model
{
    public $timestamps = false;

    protected $table = 'download_events';

    protected $fillable = [
        'platform',
        'version',
        'sha256',
        'source',
        'ip_hash',
        'user_agent_family',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
