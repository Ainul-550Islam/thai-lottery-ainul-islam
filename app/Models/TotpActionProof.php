<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TotpActionProof extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['token_hash', 'session_hash', 'credential_binding'];

    protected function casts(): array
    {
        return [
            'totp_credential_id' => 'integer',
            'issued_at_epoch' => 'integer',
            'expires_at_epoch' => 'integer',
            'consumed_at_epoch' => 'integer',
        ];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(TotpCredential::class, 'totp_credential_id');
    }
}
