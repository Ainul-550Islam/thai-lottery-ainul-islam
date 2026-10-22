<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Immutable-after-publication, versioned Terms or Privacy Notice content.
 *
 * @property int $id
 * @property string $document_type
 * @property string $locale
 * @property string $version
 * @property string $title
 * @property string $meta_title
 * @property string $meta_description
 * @property array<string, mixed> $sections
 * @property string $content_hash
 * @property Carbon $effective_at
 * @property string $status
 * @property int|null $created_by
 * @property int|null $published_by
 * @property Carbon|null $published_at
 */
final class LegalDocument extends Model
{
    protected $table = 'legal_documents';

    /** @var list<string> */
    protected $fillable = [
        'document_type',
        'locale',
        'version',
        'title',
        'meta_title',
        'meta_description',
        'sections',
        'content_hash',
        'effective_at',
        'status',
        'created_by',
        'published_by',
        'published_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'effective_at' => 'date',
            'published_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeDraft(Builder $query): void
    {
        $query->where('status', 'draft');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** @return HasMany<UserConsent, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(UserConsent::class, 'legal_document_id');
    }
}
