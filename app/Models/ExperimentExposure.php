<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExperimentExposure extends Model
{
    protected $table = 'experiment_exposures';

    protected $fillable = [
        'experiment_key',
        'subject_hash',
        'user_id',
        'variant',
        'exposed_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'exposed_at' => 'datetime',
        ];
    }
}
