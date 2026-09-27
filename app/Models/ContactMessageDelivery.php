<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to hand a contact message to an outbound provider (PROMPT 10).
 *
 * NOTHING SECRET IS STORABLE HERE. There is no attribute for a host, a
 * username, a password, a token or a provider's error text, so no code path -
 * including a future one written by someone who has not read this file - can
 * persist one through this model. What is recorded is an error CLASS and a
 * short sanitised CODE: enough to distinguish a transport timeout from an
 * unconfigured provider, and not enough to leak anything.
 *
 * @property int $id
 * @property int $contact_message_id
 * @property string $provider
 * @property string $state
 * @property Carbon $attempted_at
 * @property string|null $error_code
 * @property string|null $error_class
 */
class ContactMessageDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_message_id',
        'provider',
        'state',
        'attempted_at',
        'error_code',
        'error_class',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ContactMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ContactMessage::class, 'contact_message_id');
    }
}
