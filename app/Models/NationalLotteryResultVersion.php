<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Lottery\ProvidesResultProvenance;
use App\Enums\ResultVersionState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Immutable provenance for one attempt to state a draw's result (PROMPT 5).
 *
 * WHAT "IMMUTABLE" MEANS HERE, PRECISELY
 * ---------------------------------------------------------------------------
 * The provenance facts of a version - who said it, when, from where, and what
 * it hashed to - can never change, because changing them would make the audit
 * trail a work of fiction. The model boots a guard that refuses to save any
 * dirty attribute outside a small allow-list, and refuses deletion outright.
 *
 * The allow-list exists because two things legitimately move AFTER a row is
 * written, and both are themselves recorded acts:
 *
 *   state              a pending version becomes verified, superseded or
 *                      conflict;
 *   conflict_reason /  filled when a disagreement is detected;
 *   resolution_*       filled when an authorised operator resolves one;
 *   supersedes_*       set when this version replaces an earlier one.
 *
 * Everything else - provider, fingerprints, parser_version, imported_at,
 * source_state - is frozen at insert.
 *
 * NO SECRETS. source_endpoint_host is a host. There is no column for a token,
 * a header, a credential or a full URL, so a provenance page cannot leak one.
 *
 * @property int $id
 * @property string $uuid
 * @property int $draw_id
 * @property int $version_number
 * @property ResultVersionState $state
 * @property string $provider
 * @property string $source_state
 * @property string|null $source_identifier
 * @property string|null $source_endpoint_host
 * @property string $payload_fingerprint
 * @property string $normalized_fingerprint
 * @property string $parser_version
 * @property Carbon|null $retrieved_at
 * @property Carbon $imported_at
 * @property int|null $imported_by
 * @property int|null $supersedes_version_id
 * @property int|null $supersedes_version_number
 * @property string|null $conflict_reason
 * @property string|null $resolution_reason
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property string $validation_status
 * @property array<int, string>|null $validation_errors
 * @property array<string, mixed>|null $audit
 */
class NationalLotteryResultVersion extends Model implements ProvidesResultProvenance
{
    use HasFactory;

    public const VALIDATION_PASSED = 'passed';

    public const VALIDATION_FAILED = 'failed';

    public const VALIDATION_PENDING = 'pending';

    /**
     * The only attributes a saved version may ever change. Everything else is
     * frozen at insert time.
     *
     * @var list<string>
     */
    private const MUTABLE_AFTER_INSERT = [
        'state',
        'conflict_reason',
        'resolution_reason',
        'resolved_by',
        'resolved_at',
        'supersedes_version_id',
        'supersedes_version_number',
        'audit',
        'updated_at',
    ];

    protected $table = 'national_lottery_result_versions';

    protected $fillable = [
        'uuid',
        'draw_id',
        'version_number',
        'state',
        'provider',
        'source_state',
        'source_identifier',
        'source_endpoint_host',
        'payload_fingerprint',
        'normalized_fingerprint',
        'parser_version',
        'retrieved_at',
        'imported_at',
        'imported_by',
        'supersedes_version_id',
        'supersedes_version_number',
        'conflict_reason',
        'resolution_reason',
        'resolved_by',
        'resolved_at',
        'validation_status',
        'validation_errors',
        'audit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'supersedes_version_number' => 'integer',
            'state' => ResultVersionState::class,
            'retrieved_at' => 'datetime',
            'imported_at' => 'datetime',
            'resolved_at' => 'datetime',
            'validation_errors' => 'array',
            'audit' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ((string) $model->uuid === '') {
                $model->uuid = (string) Str::uuid();
            }

            if ($model->imported_at === null) {
                $model->imported_at = now();
            }
        });

        static::updating(function (self $model): bool {
            foreach (array_keys($model->getDirty()) as $attribute) {
                if (! in_array($attribute, self::MUTABLE_AFTER_INSERT, true)) {
                    // Refuse the whole save. A provenance fact does not move.
                    return false;
                }
            }

            return true;
        });

        // Provenance is never deleted; a version is superseded, not removed.
        static::deleting(fn (): bool => false);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<NationalLotteryDraw, $this>
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(NationalLotteryDraw::class, 'draw_id');
    }

    /**
     * @return HasOne<NationalLotteryResult, $this>
     */
    public function result(): HasOne
    {
        return $this->hasOne(NationalLotteryResult::class, 'result_version_id');
    }

    public function isPublishable(): bool
    {
        return $this->state instanceof ResultVersionState
            && $this->state->isPublishable();
    }

    // ---------------------------------------------------------------- //
    // App\Contracts\Lottery\ProvidesResultProvenance
    //
    // These accessors are the ONLY route from this row to a public page.
    // There is deliberately no accessor for the endpoint URL, a token, the
    // importer's user id or the raw payload - the interface has no way to
    // express them, so this class has no way to leak them through it.
    // ---------------------------------------------------------------- //

    public function provenanceProvider(): string
    {
        return (string) $this->provider;
    }

    public function provenanceSourceState(): string
    {
        return (string) $this->source_state;
    }

    public function provenanceSourceIdentifier(): ?string
    {
        return $this->source_identifier !== null ? (string) $this->source_identifier : null;
    }

    public function provenanceSourceHost(): ?string
    {
        return $this->source_endpoint_host !== null ? (string) $this->source_endpoint_host : null;
    }

    public function provenancePayloadFingerprint(): string
    {
        return (string) $this->payload_fingerprint;
    }

    public function provenanceNormalizedFingerprint(): string
    {
        return (string) $this->normalized_fingerprint;
    }

    public function provenanceParserVersion(): string
    {
        return (string) $this->parser_version;
    }

    public function provenanceRetrievedAtIso(): ?string
    {
        return $this->retrieved_at?->toIso8601String();
    }

    public function provenanceImportedAtIso(): ?string
    {
        return $this->imported_at?->toIso8601String();
    }

    public function provenanceVersionNumber(): int
    {
        return (int) $this->version_number;
    }

    public function provenanceSupersedesVersionNumber(): ?int
    {
        return $this->supersedes_version_number !== null
            ? (int) $this->supersedes_version_number
            : null;
    }

    /**
     * Public-safe provenance projection.
     *
     * Excludes the database id, draw_id, imported_by and resolved_by (those
     * are user ids), the validation_errors array (internal parser detail) and
     * anything that could carry a credential. The fingerprints ARE published:
     * they are one-way hashes, and they are what makes the claim checkable.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'version' => $this->provenanceVersionNumber(),
            'reference' => (string) $this->uuid,
            'state' => $this->state instanceof ResultVersionState
                ? $this->state->value
                : (string) $this->state,
            'provider' => $this->provenanceProvider(),
            'source_state' => $this->provenanceSourceState(),
            'source_identifier' => $this->provenanceSourceIdentifier(),
            'source_host' => $this->provenanceSourceHost(),
            'payload_fingerprint' => $this->provenancePayloadFingerprint(),
            'normalized_fingerprint' => $this->provenanceNormalizedFingerprint(),
            'parser_version' => $this->provenanceParserVersion(),
            'retrieved_at' => $this->provenanceRetrievedAtIso(),
            'imported_at' => $this->provenanceImportedAtIso(),
            'supersedes_version' => $this->provenanceSupersedesVersionNumber(),
            'has_conflict' => $this->state === ResultVersionState::Conflict,
        ];
    }
}
