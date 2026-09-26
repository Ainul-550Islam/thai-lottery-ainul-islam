<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable account-grade calculation snapshot (history evidence).
 *
 * Rows are append-only: grade changes create a new snapshot; existing
 * rows are never rewritten. Fingerprint is unique so concurrent
 * recalculations of the same period cannot double-insert.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $qualifying_spend
 * @property string $grade_key
 * @property string|null $previous_grade_key
 * @property string $rule_version
 * @property Carbon $calculated_at
 * @property string $fingerprint
 * @property array<string, mixed>|null $metadata
 */
class AccountGradeSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'period_start',
        'period_end',
        'qualifying_spend',
        'grade_key',
        'previous_grade_key',
        'rule_version',
        'calculated_at',
        'fingerprint',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'qualifying_spend' => 'decimal:2',
            'calculated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
