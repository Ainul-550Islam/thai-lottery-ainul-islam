<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CommissionRule extends Model
{
    use HasUuids;

    protected $table = 'commission_rules';

    protected $fillable = [
        'game_type',
        'tier_level',
        'override_percentage',
        'calculation_basis',
        'is_active',
    ];

    protected $casts = [
        'tier_level' => 'integer',
        'override_percentage' => 'string',
        'is_active' => 'boolean',
    ];
}
