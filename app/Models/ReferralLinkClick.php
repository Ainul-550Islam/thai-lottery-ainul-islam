<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralLinkClick extends Model
{
    protected $table = 'referral_link_clicks';

    protected $fillable = [
        'agent_id',
        'agent_code',
        'ip_hash',
        'referrer_host',
        'locale',
        'converted_user_id',
        'clicked_at',
    ];

    protected function casts(): array
    {
        return [
            'agent_id' => 'integer',
            'converted_user_id' => 'integer',
            'clicked_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }
}
