<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ComplianceAttestationCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One piece of evidence that a claim is true.
 *
 * THE VALIDITY PREDICATE IS THE WHOLE POINT
 * scopeValid() is the only way this model should be read on a public path:
 *
 *   revoked_at IS NULL
 *   AND valid_from <= now
 *   AND (valid_to IS NULL OR valid_to >= now)
 *
 * ComplianceBadgeService calls nothing else. A badge that survives revocation
 * or expiry is worse than no badge at all, because it is a false statement made
 * by the operator with the system's authority.
 *
 * `document_hash` (SHA-256) is what ties the row to one specific version of the
 * evidence. The evidence file is NOT stored in this repository: git is the wrong
 * place for a licence PDF, and a hash is enough to prove which document was
 * relied on when the claim was made.
 *
 * @property int $id
 * @property ComplianceAttestationCode $code
 * @property string $reference
 * @property string|null $issuer
 * @property string|null $document_hash
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property int|null $verified_by_user_id
 * @property Carbon|null $verified_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property string|null $revoked_reason
 * @property string|null $public_label
 * @property string|null $public_url
 * @property array<string, mixed>|null $meta
 */
class ComplianceAttestation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'reference',
        'issuer',
        'document_hash',
        'valid_from',
        'valid_to',
        'verified_by_user_id',
        'verified_at',
        'revoked_at',
        'revoked_by_user_id',
        'revoked_reason',
        'public_label',
        'public_url',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => ComplianceAttestationCode::class,
            'valid_from' => 'date',
            'valid_to' => 'date',
            'verified_at' => 'datetime',
            'revoked_at' => 'datetime',
            'meta' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    /**
     * Rows that may be published at the given moment.
     *
     * This is the ONLY scope a public surface may use. It is deliberately
     * verbose rather than clever: a reader can see all three conditions and
     * check them against the compliance policy without opening another file.
     */
    public function scopeValid(Builder $query, ?Carbon $at = null): Builder
    {
        $moment = $at ?? Carbon::now();

        return $query
            ->whereNull('revoked_at')
            ->where('valid_from', '<=', $moment->toDateString())
            ->where(function (Builder $inner) use ($moment): void {
                $inner->whereNull('valid_to')->orWhere('valid_to', '>=', $moment->toDateString());
            });
    }

    public function scopeOfCode(Builder $query, ComplianceAttestationCode $code): Builder
    {
        return $query->where('code', $code->value);
    }

    public function scopeRevoked(Builder $query): Builder
    {
        return $query->whereNotNull('revoked_at');
    }

    /**
     * Whether this row is publishable right now. Mirrors scopeValid() for a
     * single already-loaded row so a caller cannot accidentally publish a row
     * it read with a different (unscoped) query.
     */
    public function isValidAt(?Carbon $at = null): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        $today = ($at ?? Carbon::now())->toDateString();

        if ($this->valid_from->toDateString() > $today) {
            return false;
        }

        return $this->valid_to === null || $this->valid_to->toDateString() >= $today;
    }

    /**
     * Days until expiry, or null when the row does not expire. Used by the
     * Filament table and by the expiry-warning command; it is never shown to a
     * player.
     */
    public function daysUntilExpiry(?Carbon $at = null): ?int
    {
        if ($this->valid_to === null) {
            return null;
        }

        $today = ($at ?? Carbon::now())->startOfDay();
        $end = $this->valid_to->copy()->startOfDay();

        if ($end->lessThan($today)) {
            return 0;
        }

        return (int) $today->diffInDays($end);
    }

    /**
     * Whether the row carries the evidence Batch 3 requires before a licence
     * badge may be published: a reference AND a document hash. A row without a
     * hash is a note, not evidence.
     */
    public function hasEvidence(): bool
    {
        return trim($this->reference) !== ''
            && is_string($this->document_hash)
            && preg_match('/^[a-f0-9]{64}$/i', $this->document_hash) === 1;
    }
}
