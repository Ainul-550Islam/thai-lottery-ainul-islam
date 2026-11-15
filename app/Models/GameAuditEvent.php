<?php

declare(strict_types=1);

namespace App\Models;

use LogicException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ONE ROW OF THE DIGIT LANE'S AUDIT TRAIL. IT CAN BE CREATED AND NEVER CHANGED.
 *
 * WHY THE MODEL REFUSES UPDATE AND DELETE ITSELF
 * An audit row that a later code path can quietly edit is a log, not an audit. Refusing
 * the write here, in the model's own lifecycle hooks, means a future "tidy-up" job or an
 * admin screen cannot rewrite history by accident: it fails loudly the first time it
 * tries. (A raw query builder statement can still bypass a model; that is why the
 * architecture suite also pins WHO may write the table at all.)
 *
 * THIS MODEL DOES NOT DECIDE ANYTHING
 * It stores what GameAuditRecorder was told. Which events exist, and when one is raised,
 * is the recorder's and its callers' business.
 *
 * @property int $id
 * @property string $event
 * @property string|null $game_code
 * @property int|null $actor_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $context
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 */
class GameAuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event',
        'game_code',
        'actor_id',
        'subject_type',
        'subject_id',
        'context',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
            'actor_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('game_audit_events is append-only: a row cannot be updated.');
        });

        static::deleting(static function (): never {
            throw new LogicException('game_audit_events is append-only: a row cannot be deleted.');
        });
    }
}
