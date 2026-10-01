<?php

// TYPE: Eloquent model
// PURPOSE: Owner-scoped support case aggregate for authenticated player support.

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class SupportCase extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';
    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';

    protected $fillable = [
        'uuid',
        'public_reference',
        'owner_user_id',
        'category',
        'priority',
        'status',
        'subject',
        'closed_at',
    ];

    protected $hidden = [
        'id',
        'uuid',
        'owner_user_id',
    ];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
            $model->public_reference ??= self::buildPublicReference();
        });
    }

    public static function buildPublicReference(): string
    {
        return 'SC-'.strtoupper(Str::random(12));
    }

    public function getRouteKeyName(): string
    {
        return 'public_reference';
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<SupportMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'support_case_id');
    }
}
