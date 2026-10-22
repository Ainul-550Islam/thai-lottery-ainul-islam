<?php

declare(strict_types=1);

namespace App\Models\Chat;

use App\Enums\Chat\ChatMessageKind;
use App\Enums\Chat\ChatSenderType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line in a conversation timeline.
 *
 * ============================================================================
 * APPEND ONLY
 * ============================================================================
 * $fillable covers the moment of creation. After insert, exactly three
 * columns may ever change, and each is delivery bookkeeping rather than
 * content:
 *
 *   delivered_at, read_at   — set once, never cleared
 *   redacted_at             — set by the retention job, with `body` replaced
 *                             in the same statement
 *
 * `body` is otherwise immutable. That is what lets the timeline be quoted
 * back in a dispute: what an agent said, and when, does not change later.
 * There is deliberately no `updateBody()` on this model and no service that
 * offers one.
 *
 * ============================================================================
 * @property int                     $id
 * @property int                     $chat_conversation_id
 * @property ChatSenderType          $sender_type
 * @property int|null                $sender_user_id
 * @property string|null             $sender_display_name
 * @property ChatMessageKind         $kind
 * @property string                  $body
 * @property array<int, array<string, mixed>>|null $attachments
 * @property Carbon|null             $delivered_at
 * @property Carbon|null             $read_at
 * @property Carbon|null             $redacted_at
 * @property string|null             $client_token
 * @property Carbon|null             $created_at
 * @property Carbon|null             $updated_at
 */
class ChatMessage extends Model
{
    /** @var string */
    protected $table = 'chat_messages';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'chat_conversation_id',
        'sender_type',
        'sender_user_id',
        'sender_display_name',
        'kind',
        'body',
        'attachments',
        'client_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sender_type' => ChatSenderType::class,
            'kind' => ChatMessageKind::class,
            'attachments' => 'array',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'redacted_at' => 'datetime',
            'chat_conversation_id' => 'integer',
            'sender_user_id' => 'integer',
        ];
    }

    // ========================================================================
    // Relations
    // ========================================================================

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'sender_user_id');
    }

    // ========================================================================
    // Scopes
    // ========================================================================

    /**
     * Only what a visitor may be shown.
     */
    public function scopeVisibleToVisitor(Builder $query): Builder
    {
        return $query->whereIn('kind', array_map(
            static fn (ChatMessageKind $k): string => $k->value,
            ChatMessageKind::transcriptKinds(),
        ));
    }

    /**
     * Only internal notes.
     */
    public function scopeInternalNotes(Builder $query): Builder
    {
        return $query->where('kind', ChatMessageKind::Note->value);
    }

    /**
     * Messages the given side has not read yet.
     *
     * Used by the unread counters and by "what did I miss" on resume.
     */
    public function scopeUnreadFor(Builder $query, ChatSenderType $reader): Builder
    {
        return $query
            ->whereNull('read_at')
            ->where('sender_type', '!=', $reader->value);
    }

    /**
     * Messages written after a given message id — the poll cursor.
     *
     * Cursor by ID, not by timestamp: two messages can share a second, and
     * a timestamp cursor would either repeat one or skip one depending on
     * which microsecond the client happened to report.
     */
    public function scopeAfterId(Builder $query, int $afterId): Builder
    {
        return $query->where('id', '>', $afterId);
    }

    /**
     * Rows the retention job may still redact.
     */
    public function scopeRedactable(Builder $query, Carbon $olderThan): Builder
    {
        return $query
            ->whereNull('redacted_at')
            ->where('created_at', '<=', $olderThan)
            // System lines carry no personal data and are left intact so the
            // timeline still explains itself after a purge.
            ->where('kind', '!=', ChatMessageKind::System->value);
    }

    // ========================================================================
    // Derived facts
    // ========================================================================

    public function isFromVisitor(): bool
    {
        return $this->sender_type->isVisitor();
    }

    public function isFromAgent(): bool
    {
        return $this->sender_type->isAgent();
    }

    public function isInternalNote(): bool
    {
        return $this->kind === ChatMessageKind::Note;
    }

    /**
     * Whether the visitor may see this line. Delegates to the enum so the
     * rule exists in exactly one place.
     */
    public function isVisibleToVisitor(): bool
    {
        return $this->kind->isVisibleToVisitor();
    }

    public function isRedacted(): bool
    {
        return $this->redacted_at !== null;
    }

    /**
     * The body as it should be shown, honouring redaction.
     *
     * A redacted message is not hidden — the fact that something was said
     * and later removed by policy is itself information an auditor needs.
     * The tombstone is supplied by the caller's configuration so the wording
     * is a deployment decision, with a safe fallback.
     */
    public function displayBody(): string
    {
        if ($this->isRedacted()) {
            return (string) config('chat.retention.tombstone', '[redacted]');
        }

        return (string) $this->body;
    }

    /**
     * Attachment metadata safe to hand to a client.
     *
     * Deliberately strips `path` and `disk`: those are server-side routing
     * facts, and handing them to a browser invites a client to construct its
     * own URL around a private disk. The client gets an opaque index and the
     * facts it can legitimately display; the download endpoint re-derives
     * everything else from the stored row after an ownership check.
     *
     * @return array<int, array<string, mixed>>
     */
    public function publicAttachments(): array
    {
        $attachments = $this->attachments ?? [];

        $out = [];

        foreach (array_values($attachments) as $index => $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $out[] = [
                'index' => $index,
                'name' => (string) ($attachment['original_name'] ?? 'attachment'),
                'mime' => (string) ($attachment['mime'] ?? 'application/octet-stream'),
                'size' => (int) ($attachment['size'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Whether this line carries any attachment.
     */
    public function hasAttachments(): bool
    {
        return is_array($this->attachments) && $this->attachments !== [];
    }
}
