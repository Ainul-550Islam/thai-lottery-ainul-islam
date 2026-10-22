<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Admission counts, not successful/failed OTP results. */
final class TotpAttemptWindow extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'window_started_at_epoch' => 'integer',
            'attempts' => 'integer',
        ];
    }
}
