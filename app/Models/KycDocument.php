<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KycDocumentType;
use App\Enums\KycDocumentVerificationStatus;
use App\Enums\KycStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * KYC Verification Document Model.
 *
 * @property int $id
 * @property int $user_id
 * @property KycDocumentType $document_type
 * @property string|null $document_number
 * @property string $file_path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $file_size
 * @property KycStatus $status
 * @property KycDocumentVerificationStatus $verification_status
 * @property string|null $rejection_reason
 * @property Carbon|null $verified_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $submitted_at
 * @property int|null $verified_by
 * @property string|null $verification_reference
 * @property string|null $document_fingerprint
 * @property string|null $issuer_country
 * @property array<string, mixed>|null $metadata
 *
 * @use HasFactory<\Database\Factories\KycDocumentFactory>
 */
class KycDocument extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'document_type',
        'document_number',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'status',
        'rejection_reason',
        'verified_at',
        'verified_by',
        'metadata',
        // Batch-13 authoritative lane (additive columns).
        'issuer_country',
        'document_fingerprint',
        'verification_reference',
        'expires_at',
        'submitted_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->original_filename ??= basename((string) $model->file_path);
            $model->submitted_at ??= now();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => KycDocumentType::class,
            'status' => KycStatus::class,
            // The authoritative lifecycle column has had an enum since the batch-13 migration and
            // was never cast, so every reader compared it as a string ('verified' === $enum was
            // false). Casting it here makes the two lanes speak the same type; DB-level
            // `where('verification_status', ...->value)` queries are unaffected.
            'verification_status' => KycDocumentVerificationStatus::class,
            'file_size' => 'integer',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isVerified(): bool
    {
        return $this->status === KycStatus::Verified;
    }

    /**
     * The document's verification state, tolerating a row that was read without casts.
     *
     * The model casts `verification_status` to the enum, so the ordinary path is a straight read.
     * `getRawOriginal()` covers the other path — a row loaded through the query builder or a
     * report that selects the column as text — where the attribute really is a string. Doing this
     * once, here, is why four call sites no longer each carry an `instanceof` branch that can
     * never be false.
     */
    public function verificationState(): ?KycDocumentVerificationStatus
    {
        $raw = $this->getRawOriginal('verification_status');

        if (is_string($raw) && $raw !== '') {
            return KycDocumentVerificationStatus::tryFrom($raw);
        }

        return $this->verification_status;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', KycStatus::Pending);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', KycStatus::Verified);
    }
}
