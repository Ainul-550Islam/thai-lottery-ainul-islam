<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentHierarchy extends Model
{
    use HasUuids;

    protected $table = 'agent_hierarchies';

    protected $fillable = [
        'ancestor_id',
        'descendant_id',
        'depth',
    ];

    protected $casts = [
        'depth' => 'integer',
    ];

    public function ancestor(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'ancestor_id', 'id');
    }

    public function descendant(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'descendant_id', 'id');
    }
}
