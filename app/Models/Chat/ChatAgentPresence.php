<?php

declare(strict_types=1);

namespace App\Models\Chat;

use App\Enums\Chat\ChatAgentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One operator's observed availability.
 *
 * ============================================================================
 * THE STATUS COLUMN IS A CACHE, NOT THE TRUTH
 * ============================================================================
 * `status` is written on every heartbeat, but it is always DERIVED from
 * `last_heartbeat_at` through ChatAgentStatus::fromHeartbeatAge(). Reading
 * the raw column is therefore never the whole answer: a process that died
 * between heartbeats leaves a row that still says 'online'.
 *
 * `effectiveStatus()` is the method every caller should use, and it
 * re-derives the value whenever the stored one has gone stale. The column
 * exists so the common case — a fresh heartbeat — needs no arithmetic, not
 * so the value can be trusted on its own.
 *
 * ============================================================================
 * @property int                  $id
 * @property int                  $user_id
 * @property ChatAgentStatus      $status
 * @property Carbon|null          $last_heartbeat_at
 * @property Carbon|null          $session_started_at
 * @property int|null             $max_conversations
 * @property bool                 $accepts_new_conversations
 * @property string|null          $current_page
 */
class ChatAgentPresence extends Model
{
    /** @var string */
    protected $table = 'chat_agent_presences';

    /**
     * Only the columns an operator may set about themselves. Availability
     * routing flags (status, last_heartbeat_at) are written by the presence
     * service from an observed heartbeat, never assigned directly — an
     * agent could otherwise mark themselves online from a dead tab.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'max_conversations',
        'accepts_new_conversations',
        'current_page',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ChatAgentStatus::class,
            'last_heartbeat_at' => 'datetime',
            'session_started_at' => 'datetime',
            'accepts_new_conversations' => 'boolean',
            'max_conversations' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ========================================================================
    // Scopes
    // ========================================================================

    /**
     * Agents whose last heartbeat is recent enough to be considered Online.
     *
     * The threshold is applied in SQL against the stored heartbeat rather
     * than against the cached status column, so a crashed process drops out
     * of the routing pool on time without any cleanup job being required to
     * have run.
     */
    public function scopeOnline(Builder $query, ?Carbon $now = null): Builder
    {
        $now ??= Carbon::now();
        $threshold = $now->copy()->subSeconds((int) config('chat.presence.away_after_seconds', 90));

        return $query->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '>=', $threshold);
    }

    /**
     * An agent's own row, which may not exist yet for a first-time operator.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    // ========================================================================
    // Derived facts
    // ========================================================================

    /**
     * Heartbeat age in seconds, or null when the agent has never checked in.
     */
    public function heartbeatAgeSeconds(?Carbon $now = null): ?int
    {
        if ($this->last_heartbeat_at === null) {
            return null;
        }

        $now ??= Carbon::now();

        return (int) $this->last_heartbeat_at->diffInSeconds($now);
    }

    /**
     * The authoritative availability, re-derived from the heartbeat.
     *
     * THE method every caller should use. See the class header for why the
     * stored column is not enough.
     */
    public function effectiveStatus(?Carbon $now = null): ChatAgentStatus
    {
        return ChatAgentStatus::fromHeartbeatAge(
            $this->heartbeatAgeSeconds($now),
            (int) config('chat.presence.away_after_seconds', 90),
            (int) config('chat.presence.offline_after_seconds', 240),
        );
    }

    /**
     * Whether this agent may be handed a new conversation right now.
     *
     * Three independent conditions, and all three are required: present,
     * deliberately accepting work, and not already saturated. Any one of
     * them being false is a complete reason to route elsewhere.
     */
    public function canAcceptNewConversation(?Carbon $now = null): bool
    {
        if (! $this->effectiveStatus($now)->isAssignable()) {
            return false;
        }

        return $this->accepts_new_conversations && ! $this->isAtCapacity();
    }

    /**
     * The number of ACTIVE conversations this agent may hold.
     *
     * Falls back to the platform default, so a deployment that never touches
     * per-agent rows still has a working ceiling.
     */
    public function capacity(): int
    {
        $configured = $this->max_conversations;
        $default = (int) config('chat.queue.max_concurrent_per_agent', 8);

        if (! is_int($configured) || $configured < 1) {
            return max(1, $default);
        }

        return $configured;
    }

    /**
     * Whether the agent is already holding as many conversations as allowed.
     *
     * Counts only ACTIVE ones: a WAITING conversation has not been picked up
     * and a CLOSED one is finished, so neither should stop the agent being
     * given something new.
     */
    public function isAtCapacity(?Carbon $now = null): bool
    {
        $active = ChatConversation::query()
            ->where('assigned_agent_id', $this->user_id)
            ->active()
            ->count();

        return $active >= $this->capacity();
    }

    /**
     * How long the current stretch of availability has lasted, in seconds.
     */
    public function shiftSeconds(?Carbon $now = null): ?int
    {
        if ($this->session_started_at === null) {
            return null;
        }

        $now ??= Carbon::now();

        return max(0, (int) $this->session_started_at->diffInSeconds($now));
    }
}
