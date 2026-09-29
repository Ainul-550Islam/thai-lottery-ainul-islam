<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountGradeLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APPEND-ONLY account grade snapshot.
 *
 * One row records one evaluation of a user's grade: the exact window,
 * the qualifying spend seen, the resolved grade, the grade discount
 * rate, the entitlement hash and the rule/source versions that
 * produced it. Rows are never updated — a changed evaluation writes a
 * NEW row, so the ladder's history is evidence, not an editable log.
 *
 * GRADE PARITY BATCH columns (rule_version '2'):
 *   grade_discount_rate  the tier's fraction the evaluation applied
 *   entitlement_hash     stable hash of (level, ordered games, rv)
 *   source_version       the evaluator algorithm version ('2')
 *
 * Historical rows from retired tiers (silver/gold/platinum) are kept
 * verbatim: they are records of what WAS true, never rewritten to the
 * new vocabulary.
 */
final class AccountGradeSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'period_start',
        'period_end',
        'qualifying_spend',
        'grade_key',
        'grade_discount_rate',
        'entitlement_hash',
        'previous_grade_key',
        'rule_version',
        'source_version',
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
            'grade_discount_rate' => 'decimal:4',
            'calculated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * The user this snapshot belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The resolved grade level, with historical/unknown keys resolving
     * defensively to the base state.
     */
    public function level(): AccountGradeLevel
    {
        return AccountGradeLevel::fromKeyOrDefault((string) $this->grade_key);
    }
}
