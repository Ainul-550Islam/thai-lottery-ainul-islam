<?php

declare(strict_types=1);

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One operational fact about a conversation: claimed, assigned, closed,
 * warned, redacted.
 *
 * ============================================================================
 * APPEND ONLY — NO updated_at
 * ============================================================================
 * This model sets UPDATED_AT to null on purpose. An audit row that carries a
 * modification timestamp invites the question "what changed?", and the answer
 * for this table must always be "nothing". Eloquent will therefore never try
 * to write updated_at, and no service in the feature offers an update method.
 *
 * ORDERING NOTE
 * The canonical order is by `id`, not `occurred_at`: two events can share a
 * second, and `occurred_at` is additionally supplied by callers who may be
 * describing something that happened slightly before the write. The primary
 * key is the only ordering that is guaranteed to match the order the events
 * were actually recorded in.
 *
 * ============================================================================
 * @property int                    $id
 * @property int|null               $chat_conversation_id
 * @property string                 $event
 * @property int|null               $actor_user_id
 * @property string                 $actor_type
 * @property array<string, mixed>|null $context
 * @property string|null            $correlation_id
 * @property Carbon|null            $occurred_at
 * @property Carbon|null            $created_at
 */
class ChatEvent extends Model
{
    /** @var string */
    protected $table = 'chat_events';

    /**
     * An audit row is written once. See the class header.
     */
    public const UPDATED_AT = null;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'chat_conversation_id',
        'event',
        'actor_user_id',
        'actor_type',
        'context',
        'correlation_id',
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
            'created_at' => 'datetime',
            'chat_conversation_id' => 'integer',
            'actor_user_id' => 'integer',
        ];
    }

    // ========================================================================
    // Event vocabulary
    // ========================================================================
    // Named constants rather than bare strings scattered through services:
    // the health queries and the tests both need to refer to the same words
    // the writers use, and a typo in a literal would silently produce an
    // event nobody queries.

    public const CONVERSATION_CREATED = 'conversation_created';
    public const CONVERSATION_RESUMED = 'conversation_resumed';
    public const CONVERSATION_CLAIMED = 'conversation_claimed';
    public const CONVERSATION_ASSIGNED = 'conversation_assigned';
    public const CONVERSATION_REASSIGNED = 'conversation_reassigned';
    public const AGENT_JOINED = 'agent_joined';
    public const AGENT_LEFT = 'agent_left';
    public const MESSAGE_SENT = 'message_sent';
    public const NOTE_ADDED = 'note_added';
    public const ATTACHMENT_ADDED = 'attachment_added';
    public const ATTACHMENT_REJECTED = 'attachment_rejected';
    public const IDLE_WARNED = 'idle_warned';
    public const CONVERSATION_CLOSED = 'conversation_closed';
    public const IDLE_CLOSED = 'idle_closed';
    public const TRANSCRIPT_DISPATCHED = 'transcript_dispatched';
    public const TRANSCRIPT_FAILED = 'transcript_failed';
    public const RETENTION_REDACTED = 'retention_redacted';
    public const RATE_LIMITED = 'rate_limited';
    public const ACCESS_DENIED = 'access_denied';
    public const SLA_BREACHED = 'sla_breached';
    public const CONVERSATION_CLAIMED_BY_VISITOR = 'conversation_claimed_by_visitor';

    /**
     * @return array<int, string>
     */
    public static function events(): array
    {
        return [
            self::CONVERSATION_CREATED,
            self::CONVERSATION_RESUMED,
            self::CONVERSATION_CLAIMED,
            self::CONVERSATION_ASSIGNED,
            self::CONVERSATION_REASSIGNED,
            self::AGENT_JOINED,
            self::AGENT_LEFT,
            self::MESSAGE_SENT,
            self::NOTE_ADDED,
            self::ATTACHMENT_ADDED,
            self::ATTACHMENT_REJECTED,
            self::IDLE_WARNED,
            self::CONVERSATION_CLOSED,
            self::IDLE_CLOSED,
            self::TRANSCRIPT_DISPATCHED,
            self::TRANSCRIPT_FAILED,
            self::RETENTION_REDACTED,
            self::RATE_LIMITED,
            self::ACCESS_DENIED,
            self::SLA_BREACHED,
            self::CONVERSATION_CLAIMED_BY_VISITOR,
        ];
    }

    // ========================================================================
    // Relations
    // ========================================================================

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'actor_user_id');
    }

    // ========================================================================
    // Scopes
    // ========================================================================

    public function scopeOfEvent(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }

    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('occurred_at', [$from, $to]);
    }

    /**
     * The canonical read order. See the ordering note in the class header.
     */
    public function scopeInRecordedOrder(Builder $query): Builder
    {
        return $query->orderBy('id');
    }

    // ========================================================================
    // Derived facts
    // ========================================================================

    /**
     * A typed value out of the JSON context, or null when absent.
     *
     * Returns null for a missing key AND for a key present with a null value,
     * which is the honest answer in both cases: neither is a fact.
     */
    public function contextValue(string $key): string|int|float|bool|null
    {
        $context = $this->context ?? [];

        if (! array_key_exists($key, $context)) {
            return null;
        }

        $value = $context[$key];

        return is_scalar($value) ? $value : null;
    }

    /**
     * Whether a person caused this, rather than the platform.
     */
    public function hasHumanActor(): bool
    {
        return $this->actor_user_id !== null;
    }
}
