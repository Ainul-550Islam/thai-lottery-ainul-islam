<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One append-only line in a data-subject request's timeline.
 *
 * IMMUTABILITY IS ENFORCED IN THE MODEL, NOT ONLY BY CONVENTION. An event that
 * could be edited is not evidence: a reviewer could rewrite what a colleague
 * decided. updating, deleting and (defensively) saving an already-persisted row
 * all throw, and the table itself carries only created_at so there is no
 * updated_at column for a careless write to touch.
 *
 * actor_type is 'requester', 'system' or 'staff'. The requester is never an
 * account id: a public intake has no account, and even an authenticated one is
 * recorded by role so the timeline does not become a second user directory.
 *
 * @property int $id
 * @property int $request_id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string $event
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $note_encrypted
 * @property Carbon $created_at
 */
class DataSubjectRequestEvent extends Model
{
    use HasFactory;

    public const ACTOR_REQUESTER = 'requester';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_STAFF = 'staff';

    public const EVENT_SUBMITTED = 'submitted';

    public const EVENT_VERIFIED = 'verified';

    public const EVENT_STATUS_CHANGE = 'status_change';

    public const EVENT_NOTE = 'note';

    public const EVENT_EXPORT_BUILT = 'export_built';

    public const EVENT_EXPORT_RELEASED = 'export_released';

    public const EVENT_EXPORT_FAILED = 'export_failed';

    public const EVENT_SLA_REMINDER = 'sla_reminder';

    public const EVENT_OVERDUE = 'overdue';

    public const EVENT_RETENTION_CHECK = 'retention_check';

    public const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'actor_type',
        'actor_id',
        'event',
        'from_status',
        'to_status',
        'note_encrypted',
        'created_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'note_encrypted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'note_encrypted' => 'encrypted',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (self $event): never {
            throw new LogicException('A data-subject request event is append-only and cannot be updated.');
        });

        static::deleting(static function (self $event): never {
            throw new LogicException('A data-subject request event is append-only and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<DataSubjectRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(DataSubjectRequest::class, 'request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * A human label for the timeline, translated at render time.
     */
    public function labelKey(): string
    {
        return 'privacy_requests.events.'.$this->event;
    }

    public function label(): string
    {
        $key = $this->labelKey();
        $translated = trans($key);

        // Fall back to the raw event name rather than rendering a missing-key
        // path into an operator's screen.
        return is_string($translated) && $translated !== $key ? (string) $translated : $this->event;
    }
}
