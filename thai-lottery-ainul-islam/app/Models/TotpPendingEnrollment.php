<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pending material only; a row is never evidence of confirmed/enabled MFA. */
final class TotpPendingEnrollment extends Model
{
    protected $table = 'totp_pending_enrollments';

    protected $guarded = ['*'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'confirmation_attempts' => 'integer',
            'secret' => 'encrypted',
            'issued_at_epoch' => 'integer',
            'expires_at_epoch' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
