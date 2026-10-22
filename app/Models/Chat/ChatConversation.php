<?php

declare(strict_types=1);

namespace App\Models\Chat;

use App\Enums\Chat\ChatCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Enums\Chat\ChatPriority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A live-chat conversation.
 *
 * ============================================================================
 * MASS ASSIGNMENT
 * ============================================================================
 * $fillable carries identity and descriptive attributes ONLY. Every column
 * that decides how the conversation behaves is deliberately absent and can
 * only be changed by calling the service that owns the transition:
 *
 *   status, close_reason, assigned_agent_id, claimed_at, queued_at,
 *   first_response_at, closed_at, closed_by_user_id,
 *   visitor_unread_count, agent_unread_count, message_count
 *
 * That list is the chat feature's version of the rule the financial models
 * already follow: a caller may not assign its way into a state, because the
 * state has consequences (SLA maths, agent load, queue ordering) that
 * assigning a column cannot perform. A `$conversation->status = 'closed'`
 * would leave closed_at null and the conversation in the queue forever.
 *
 * ============================================================================
 * @property int                        $id
 * @property string                     $uuid
 * @property string                     $public_reference
 * @property int|null                   $user_id
 * @property string|null                $guest_token_hash
 * @property string|null                $guest_name
 * @property string|null                $guest_email
 * @property ChatConversationStatus     $status
 * @property ChatPriority               $priority
 * @property ChatCloseReason|null       $close_reason
 * @property int|null                   $assigned_agent_id
 * @property Carbon|null                $queued_at
 * @property Carbon|null                $claimed_at
 * @property Carbon|null                $first_response_at
 * @property Carbon|null                $last_message_at
 * @property Carbon|null                $last_visitor_message_at
 * @property Carbon|null                $last_agent_message_at
 * @property Carbon|null                $idle_warned_at
 * @property Carbon|null                $closed_at
 * @property int|null                   $closed_by_user_id
 * @property int                        $visitor_unread_count
 * @property int                        $agent_unread_count
 * @property int                        $message_count
 * @property array<string, mixed>|null  $meta
 * @property Carbon|null                $transcript_sent_at
 */
class ChatConversation extends Model
{
    /** @var string */
    protected $table = 'chat_conversations';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'public_reference',
        'user_id',
        'guest_token_hash',
        'guest_name',
        'guest_email',
        'priority',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ChatConversationStatus::class,
            'priority' => ChatPriority::class,
            'close_reason' => ChatCloseReason::class,
            'meta' => 'array',
            'queued_at' => 'datetime',
            'claimed_at' => 'datetime',
            'first_response_at' => 'datetime',
            'last_message_at' => 'datetime',
            'last_visitor_message_at' => 'datetime',
            'last_agent_message_at' => 'datetime',
            'idle_warned_at' => 'datetime',
            'closed_at' => 'datetime',
            'transcript_sent_at' => 'datetime',
            'visitor_unread_count' => 'integer',
            'agent_unread_count' => 'integer',
            'message_count' => 'integer',
            'user_id' => 'integer',
            'assigned_agent_id' => 'integer',
            'closed_by_user_id' => 'integer',
        ];
    }

    // ========================================================================
    // Relations
    // ========================================================================

    /**
     * The signed-in member, when this conversation has one.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /**
     * The agent currently holding this conversation.
     */
    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_agent_id');
    }

    /**
     * The operator who closed it, when a person did.
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'closed_by_user_id');
    }

    /**
     * The full timeline, oldest first.
     *
     * Ordering is by primary key rather than created_at because two messages
     * written inside the same second would otherwise have an unstable order
     * that differs between the visitor's poll and the agent's console.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'chat_conversation_id')->orderBy('id');
    }

    /**
     * Messages the VISITOR is allowed to see.
     *
     * The visibility rule lives on ChatMessageKind::isVisibleToVisitor();
     * this scope consults it rather than hard-coding an exclusion, so a new
     * message kind cannot be leaked by a query that forgot to exclude it.
     */
    public function visibleMessages(): HasMany
    {
        return $this->messages()
            ->whereIn('kind', \App\Enums\Chat\ChatMessageKind::transcriptKinds());
    }

    /**
     * Internal notes only.
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'chat_conversation_id')
            ->where('kind', \App\Enums\Chat\ChatMessageKind::Note->value)
            ->orderBy('id');
    }

    /**
     * The operational audit trail for this conversation.
     */
    public function events(): HasMany
    {
        return $this->hasMany(ChatEvent::class, 'chat_conversation_id')->orderBy('id');
    }

    // ========================================================================
    // Scopes — one per real query in the feature
    // ========================================================================

    /**
     * Conversations that are still open (waiting or active).
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            static fn (ChatConversationStatus $s): string => $s->value,
            ChatConversationStatus::openStatuses(),
        ));
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', ChatConversationStatus::Waiting->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ChatConversationStatus::Active->value);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', ChatConversationStatus::Closed->value);
    }

    /**
     * The operator queue ordering: most important first, then longest wait.
     *
     * Two keys, and the second one is not decoration. Priority alone would
     * let a Normal conversation from this morning sit behind every Normal
     * conversation that arrived after it; the tiebreak is what makes the
     * queue fair inside a priority band.
     */
    public function scopeInQueueOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw(
                'CASE priority '
                ."WHEN 'urgent' THEN 40 "
                ."WHEN 'high' THEN 30 "
                ."WHEN 'normal' THEN 20 "
                .'ELSE 10 END DESC'
            )
            ->orderBy('queued_at')
            ->orderBy('id');
    }

    /**
     * The most recent conversation for a browser token, if any is still open.
     */
    public function scopeForGuestToken(Builder $query, string $tokenHash): Builder
    {
        return $query->where('guest_token_hash', $tokenHash);
    }

    /**
     * Conversations idle since before the given instant and not yet closed.
     *
     * IDLE MEANS NO HUMAN SPOKE. The scan reads the two human activity clocks
     * (`last_visitor_message_at`, `last_agent_message_at`), never
     * `last_message_at` — because system lines move that one, and the idle
     * WARNING is itself a system line. Measuring from `last_message_at` would
     * mean warning a visitor "this closes in 5 minutes" and simultaneously
     * restarting the 30-minute clock from the warning, so the close lands at 35
     * minutes and every automated notice silently extends the conversation.
     *
     * The predicate is written as two "neither side spoke after the threshold"
     * conditions rather than a GREATEST()/MAX() over the pair, because the
     * scalar-max function is `GREATEST` on MySQL and Postgres but `MAX` on
     * SQLite. Three dialects for one comparison is not worth it, and the
     * conjunction below is exactly equivalent and portable.
     *
     * A conversation with no human message at all is idle from `queued_at`
     * (falling back to `created_at`): without that branch a thread nobody ever
     * picked up would have two NULL clocks, fail every comparison, and sit in
     * the queue forever.
     */
    public function scopeIdleSince(Builder $query, Carbon $threshold): Builder
    {
        return $query
            ->open()
            ->where(function (Builder $inner) use ($threshold): void {
                // Case A: at least one human spoke, and neither side spoke
                // after the threshold.
                $inner->where(function (Builder $spoke) use ($threshold): void {
                    $spoke->where(function (Builder $anyHuman) {
                        $anyHuman->whereNotNull('last_visitor_message_at')
                            ->orWhereNotNull('last_agent_message_at');
                    })
                        ->where(function (Builder $visitorQuiet) use ($threshold): void {
                            $visitorQuiet->whereNull('last_visitor_message_at')
                                ->orWhere('last_visitor_message_at', '<=', $threshold);
                        })
                        ->where(function (Builder $agentQuiet) use ($threshold): void {
                            $agentQuiet->whereNull('last_agent_message_at')
                                ->orWhere('last_agent_message_at', '<=', $threshold);
                        });
                })
                    // Case B: nobody ever spoke, so the wait itself is the
                    // silence.
                    ->orWhere(function (Builder $noHuman) use ($threshold): void {
                        $noHuman->whereNull('last_visitor_message_at')
                            ->whereNull('last_agent_message_at')
                            ->where(function (Builder $fromQueue) use ($threshold): void {
                                $fromQueue->where('queued_at', '<=', $threshold)
                                    ->orWhere(function (Builder $fromCreation) use ($threshold): void {
                                        $fromCreation->whereNull('queued_at')
                                            ->where('created_at', '<=', $threshold);
                                    });
                            });
                    });
            });
    }

    /**
     * The instant this conversation last had human activity, or null.
     *
     * The read-side counterpart of scopeIdleSince(): the same definition of
     * "idle", so a per-conversation check and a queue-wide scan cannot disagree.
     */
    public function lastHumanActivityAt(): ?Carbon
    {
        $candidates = array_filter(
            [$this->last_visitor_message_at, $this->last_agent_message_at],
            static fn ($value): bool => $value instanceof Carbon,
        );

        if ($candidates === []) {
            return null;
        }

        return max($candidates);
    }

    // ========================================================================
    // Derived facts — computed, never stored
    // ========================================================================

    /**
     * Is this conversation still open?
     */
    public function isOpen(): bool
    {
        return $this->status->acceptsMessages();
    }

    /**
     * Is it waiting for an agent to claim it?
     */
    public function isWaiting(): bool
    {
        return $this->status === ChatConversationStatus::Waiting;
    }

    /**
     * Is an agent holding it right now?
     */
    public function isActive(): bool
    {
        return $this->status === ChatConversationStatus::Active;
    }

    /**
     * Has it been closed?
     */
    public function isClosed(): bool
    {
        return $this->status === ChatConversationStatus::Closed;
    }

    /**
     * Was this visitor anonymous at the time it was opened?
     */
    public function isGuest(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Has no human answered yet?
     *
     * Reads first_response_at, which only an AGENT message sets. A system
     * notice saying "an agent will be with you shortly" must not be able to
     * mark a conversation as answered — that would report a zero wait for a
     * conversation nobody had touched.
     */
    public function isUnanswered(): bool
    {
        return $this->first_response_at === null;
    }

    /**
     * Seconds this visitor has waited for the first human reply.
     *
     * Returns 0 once answered, and null when there is no clock to measure
     * from — an unknown wait must not be rendered as an instant one.
     */
    public function waitingSeconds(?Carbon $now = null): ?int
    {
        if (! $this->isUnanswered()) {
            return 0;
        }

        $from = $this->queued_at ?? $this->created_at;

        if ($from === null) {
            return null;
        }

        $now ??= Carbon::now();

        return max(0, $now->diffInSeconds($from, false) !== false
            ? (int) $from->diffInSeconds($now)
            : 0);
    }

    /**
     * How long the whole conversation lasted, in seconds, or null while it
     * is still open.
     */
    public function durationSeconds(?Carbon $now = null): ?int
    {
        if ($this->closed_at === null || $this->created_at === null) {
            return null;
        }

        return max(0, (int) $this->created_at->diffInSeconds($this->closed_at));
    }

    /**
     * The display identity for the visitor side.
     *
     * Falls back to a stable, non-identifying label rather than an empty
     * string, so an agent's queue never shows a blank row and the label is
     * never mistaken for a real name.
     */
    public function visitorDisplayName(): string
    {
        if (is_string($this->guest_name) && trim($this->guest_name) !== '') {
            return trim($this->guest_name);
        }

        if ($this->user_id !== null) {
            $accountNumber = $this->user?->account_number ?? null;

            if (is_string($accountNumber) && $accountNumber !== '') {
                return 'Member '.$accountNumber;
            }

            return 'Member #'.$this->user_id;
        }

        return 'Guest '.substr($this->public_reference, 0, 6);
    }

    /**
     * Whether the visitor is allowed to see this conversation's content.
     *
     * Guests are matched on the hashed browser token; members on their user
     * id. A conversation claimed by a member after the guest opened it stays
     * reachable by BOTH, deliberately — the browser that started the chat is
     * still the browser the person is sitting in.
     */
    public function isAccessibleBy(?int $userId, ?string $guestTokenHash): bool
    {
        if ($userId !== null && $this->user_id === $userId) {
            return true;
        }

        if ($guestTokenHash !== null
            && $this->guest_token_hash !== null
            && hash_equals($this->guest_token_hash, $guestTokenHash)) {
            return true;
        }

        return false;
    }
}
