<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Confirmed key material, NOT login enforcement or a reusable authentication proof. */
final class TotpCredential extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['secret', 'last_accepted_step'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'secret' => 'encrypted',
            'confirmed_at_epoch' => 'integer',
            'last_accepted_step' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
