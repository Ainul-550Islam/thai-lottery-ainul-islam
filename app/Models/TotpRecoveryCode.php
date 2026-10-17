<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A high-entropy recovery-code digest; neither a raw code nor a bearer proof. */
final class TotpRecoveryCode extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'totp_credential_id' => 'integer',
            'issued_at_epoch' => 'integer',
            'consumed_at_epoch' => 'integer',
        ];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(TotpCredential::class, 'totp_credential_id');
    }
}
