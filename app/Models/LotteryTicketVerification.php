<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Immutable evidence of one public verification request (PROMPT 4).
 *
 * IMMUTABLE MEANS IMMUTABLE
 * The model boots a guard that refuses any update and any delete. Evidence is
 * an observation; editing it would defeat the purpose of keeping it. Retention
 * is handled by a scheduled prune against verified_at, not by editing rows.
 *
 * NO QUERY, NO PII
 * query_fingerprint is hash_hmac of the NORMALISED input, produced by
 * TicketIdentityService::fingerprint(). The raw ticket number or barcode
 * payload is never assigned to this model - there is no attribute for it, so
 * it cannot be added by accident.
 */
class LotteryTicketVerification extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'lottery_ticket_verifications';

    protected $fillable = [
        'uuid',
        'verification_kind',
        'product',
        'query_fingerprint',
        'public_status',
        'authenticity_state',
        'barcode_state',
        'fixture_used',
        'policy_version',
        'correlation_id',
        'metadata',
        'verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fixture_used' => 'boolean',
            'metadata' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ((string) $model->uuid === '') {
                $model->uuid = (string) Str::uuid();
            }

            if ($model->verified_at === null) {
                $model->verified_at = now();
            }
        });

        // Evidence rows are append-only.
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => false);
    }

    /**
     * Public-safe projection. Deliberately excludes the fingerprint: a caller
     * who can see the hash and can guess inputs could confirm a guess.
     *
     * @return array{uuid: string, kind: string, product: string, status: string, authenticity: string|null, verified_at: string|null}
     */
    public function toPublicArray(): array
    {
        return [
            'uuid' => (string) $this->uuid,
            'kind' => (string) $this->verification_kind,
            'product' => (string) $this->product,
            'status' => (string) $this->public_status,
            'authenticity' => $this->authenticity_state !== null ? (string) $this->authenticity_state : null,
            'verified_at' => $this->verified_at?->toIso8601String(),
        ];
    }
}
