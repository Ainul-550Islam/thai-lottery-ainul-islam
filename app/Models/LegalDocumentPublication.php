<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The single public-document pointer for a document type and locale.
 *
 * @property int $id
 * @property string $document_type
 * @property string $locale
 * @property int $legal_document_id
 * @property int|null $published_by
 * @property Carbon $published_at
 */
final class LegalDocumentPublication extends Model
{
    protected $table = 'legal_document_publications';

    /** @var list<string> */
    protected $fillable = [
        'document_type',
        'locale',
        'legal_document_id',
        'published_by',
        'published_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LegalDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
