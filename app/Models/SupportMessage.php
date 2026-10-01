<?php

// TYPE: Eloquent model
// PURPOSE: Public support-case message with owner-scoped case relation and hidden internal metadata.

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupportMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'support_case_id',
        'sender_user_id',
        'body',
        'internal',
    ];

    protected $hidden = [
        'id',
        'support_case_id',
        'sender_user_id',
        'internal',
    ];

    protected function casts(): array
    {
        return [
            'internal' => 'bool',
        ];
    }

    /** @return BelongsTo<SupportCase, $this> */
    public function supportCase(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class, 'support_case_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
