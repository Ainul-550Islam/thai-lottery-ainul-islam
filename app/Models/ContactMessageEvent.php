<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ContactMessageEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One append-only line in a support ticket's internal timeline.
 *
 * THREE KINDS, ONE TABLE. 'status_change' records who moved the ticket and
 * between which states; 'note' records an internal observation; 'reply' records
 * that an operator answered the visitor. They share a table because they share
 * an ordering - a support lead reading a complaint wants the whole story in one
 * list, not three queries merged by hand.
 *
 * NOTES AND REPLIES ARE ENCRYPTED, AND NEITHER IS PUBLIC. The public status
 * lookup reads contact_messages.status only; it never touches this table. So an
 * internal note cannot leak through the visitor-facing endpoint even by
 * accident, and a copy of the database without the key reveals no prose.
 *
 * IMMUTABLE. updating and deleting throw once the row exists.
 *
 * @property int $id
 * @property int $contact_message_id
 * @property int|null $actor_id
 * @property string $type
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $body_encrypted
 * @property Carbon $created_at
 */
class ContactMessageEvent extends Model
{
    use HasFactory;

    public const TYPE_STATUS_CHANGE = 'status_change';

    public const TYPE_NOTE = 'note';

    public const TYPE_REPLY = 'reply';

    public const UPDATED_AT = null;

    protected $fillable = [
        'contact_message_id',
        'actor_id',
        'type',
        'from_status',
        'to_status',
        'body_encrypted',
        'created_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'body_encrypted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body_encrypted' => 'encrypted',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (self $event): never {
            throw new LogicException('A contact message event is append-only and cannot be updated.');
        });

        static::deleting(static function (self $event): never {
            throw new LogicException('A contact message event is append-only and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<ContactMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ContactMessage::class, 'contact_message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function labelKey(): string
    {
        return 'support_tickets.events.'.$this->type;
    }
}
