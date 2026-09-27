<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One message a visitor sent through the public contact form (PROMPT 10).
 *
 * THE MESSAGE IS TEXT, NOT MARKUP. Nothing in this class or its templates
 * treats any field as HTML. Blade escapes every one of them, and there is no
 * accessor that returns pre-rendered markup - because the moment one exists,
 * somewhere will render it unescaped.
 *
 * ROUTE KEY IS public_reference, NEVER id. A confirmation screen quoting an
 * auto-increment key tells the visitor how many messages the platform has
 * received and invites them to count.
 *
 * WHAT THIS MODEL CANNOT EXPOSE. There is no ip_address, user_agent, header or
 * raw-payload attribute, so no projection of this model can leak one. Abuse
 * control uses sender_fingerprint, a keyed hash that identifies a repeat
 * sender without identifying a person.
 *
 * @property int $id
 * @property string $uuid
 * @property string $public_reference
 * @property string $name
 * @property string $email
 * @property string $subject
 * @property string $message
 * @property string $status
 * @property string $delivery_state
 * @property string|null $sender_fingerprint
 * @property string|null $content_fingerprint
 * @property string|null $locale
 * @property Carbon|null $resolved_at
 */
class ContactMessage extends Model
{
    use HasFactory;

    /** The application has the message. It says nothing about email. */
    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_IN_REVIEW = 'IN_REVIEW';

    public const STATUS_RESOLVED = 'RESOLVED';

    public const STATUS_SPAM = 'SPAM';

    public const STATUS_ARCHIVED = 'ARCHIVED';

    /** No delivery has been tried yet. */
    public const DELIVERY_NOT_ATTEMPTED = 'NOT_ATTEMPTED';

    /** A configured provider ACCEPTED the message. Only this means "sent". */
    public const DELIVERY_SENT = 'SENT';

    public const DELIVERY_PENDING = 'PENDING';

    /** No provider is configured. The message is stored and nothing was sent. */
    public const DELIVERY_NOT_CONFIGURED = 'NOT_CONFIGURED';

    /** A provider was configured and refused or errored. */
    public const DELIVERY_FAILED = 'FAILED';

    protected $fillable = [
        'uuid',
        'public_reference',
        'name',
        'email',
        'subject',
        'message',
        'status',
        'delivery_state',
        'sender_fingerprint',
        'content_fingerprint',
        'locale',
        'resolved_at',
    ];

    /**
     * Hidden from every array and JSON projection of this model.
     *
     * A belt-and-braces measure: the public surface never serialises a message
     * at all, but if something later does, the fingerprints must not travel
     * with it.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'sender_fingerprint',
        'content_fingerprint',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ((string) $model->uuid === '') {
                $model->uuid = (string) Str::uuid();
            }

            if ((string) $model->public_reference === '') {
                $model->public_reference = self::buildPublicReference();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_reference';
    }

    /**
     * A quotable reference with no information in it.
     *
     * Random, not sequential and not derived from the row, so two references
     * reveal nothing about volume or ordering. Upper case and digits only, so
     * it survives being read aloud or retyped from a screenshot.
     */
    public static function buildPublicReference(): string
    {
        return 'CT-'.strtoupper(Str::random(10));
    }

    /**
     * @return HasMany<ContactMessageDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ContactMessageDelivery::class, 'contact_message_id');
    }

    /**
     * Has an operator finished with this message?
     *
     * Retention reads this. An unresolved message is never purged on a
     * schedule: a support request disappearing because it aged is worse than
     * keeping it.
     */
    public function isPurgeable(): bool
    {
        $purgeable = config('contact.retention.purgeable_statuses');

        return is_array($purgeable) && in_array($this->status, $purgeable, true);
    }
}
