<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable snapshot of a user's terms acceptance or privacy-notice acknowledgement.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $legal_document_id
 * @property string $document_type
 * @property string $document_version
 * @property string $document_hash
 * @property string $action
 * @property Carbon $consented_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $locale
 * @property string $source
 */
final class UserConsent extends Model
{
    protected static function booted(): void
    {
        static::updating(static function (self $consent): never {
            throw new LogicException('Consent evidence is immutable and cannot be updated.');
        });

        static::deleting(static function (self $consent): never {
            throw new LogicException('Consent evidence is immutable and cannot be deleted.');
        });
    }

    protected $table = 'user_consents';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'legal_document_id',
        'document_type',
        'document_version',
        'document_hash',
        'action',
        'consented_at',
        'ip_address',
        'user_agent',
        'locale',
        'source',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<LegalDocument, $this> */
    public function legalDocument(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }
}
