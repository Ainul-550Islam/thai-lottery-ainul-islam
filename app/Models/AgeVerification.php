<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A minimal age-eligibility attestation record; it deliberately stores no DOB or ID image.
 *
 * @property int $id
 * @property int $user_id
 * @property string $status
 * @property int $minimum_age
 * @property string $method
 * @property Carbon $attested_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $source
 */
final class AgeVerification extends Model
{
    protected $table = 'age_verifications';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'status',
        'minimum_age',
        'method',
        'attested_at',
        'ip_address',
        'user_agent',
        'source',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'minimum_age' => 'integer',
            'attested_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
