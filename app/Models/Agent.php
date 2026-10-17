<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasUuids;

    protected $table = 'agents';

    protected $fillable = [
        'user_id',
        'agent_code',
        'parent_agent_id',
        'tier_level',
        'lineage_path',
        'status',
        'direct_commission_rate',
    ];

    protected $casts = [
        'tier_level' => 'integer',
        'direct_commission_rate' => 'string',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'parent_agent_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Agent::class, 'parent_agent_id', 'id');
    }

    public function commissionLedgers(): HasMany
    {
        return $this->hasMany(CommissionLedger::class, 'agent_id', 'id');
    }

    public function ancestors(): HasMany
    {
        return $this->hasMany(AgentHierarchy::class, 'descendant_id', 'id');
    }

    public function descendants(): HasMany
    {
        return $this->hasMany(AgentHierarchy::class, 'ancestor_id', 'id');
    }
}
