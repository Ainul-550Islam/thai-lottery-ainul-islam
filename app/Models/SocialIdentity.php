<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $provider_subject
 * @property string $email_at_link
 * @property bool $email_verified
 * @property string $linked_via
 */
final class SocialIdentity extends Model
{
    // No mass assignment: every field is set explicitly by the sign-in services.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'email_verified' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
