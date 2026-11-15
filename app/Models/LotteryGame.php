<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GameRulesStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One digit game's OPERATOR RECORD: the audited half of the game's identity.
 *
 * WHAT THIS MODEL OWNS AND WHAT IT DOES NOT
 * config/lottery_games.php owns the RULES: digit count, bet types, match modes,
 * payout multipliers, draw times, cutoffs, stake bounds, liability caps, the
 * result source contract and the publication bridge. GameRegistry is the single
 * read point for that block.
 *
 * This table owns the DECISION: has an operator verified those rules, against
 * which exact version of them (rules_checksum), and may the game be sold right
 * now (enabled). That is why the columns here are status, rules_checksum,
 * enabled, verified_by and verified_at and nothing rule-shaped: a digit count
 * stored twice would be two truths, and the second one would be the stale one.
 *
 * WHY THE TABLE EXISTS AT ALL WHEN CONFIG ALREADY SAYS 'unverified'
 * A config file is deployed by a developer; verifying a game is an operator act
 * that must be attributable, permission-gated, timestamped and revocable. The
 * config value is only the declaration the developer shipped, and GameRegistry
 * compares it against this row so that a config edit cannot silently re-verify a
 * game an operator suspended, and a suspension cannot be undone by a redeploy.
 *
 * FAIL CLOSED
 * A game with no row is unknown to the operator and is refused by GameRegistry.
 * A game with a row whose status is not Verified is not sellable no matter what
 * else is configured. A game whose stored rules_checksum no longer matches the
 * checksum of the current configuration is treated as unverified, because the
 * operator signed off on rules that have since changed.
 *
 * NO BEHAVIOUR LIVES HERE
 * Selling, refusing, scheduling, ingesting and settling are services. This model
 * stores facts and answers questions about them.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property GameRulesStatus $status
 * @property string|null $rules_checksum
 * @property bool $enabled
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<string, mixed>|null $metadata
 */
class LotteryGame extends Model
{
    /**
     * The seeder, the Filament verify/suspend/resume actions and the test helper
     * are the only writers. Everything else reads.
     */
    protected $fillable = [
        'code',
        'name',
        'status',
        'rules_checksum',
        'enabled',
        'verified_by',
        'verified_at',
        'metadata',
    ];

    /**
     * A game code is a stable machine identity, never a display string, so it is
     * the route key in the admin panel and in the API rather than the id.
     */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GameRulesStatus::class,
            'rules_checksum' => 'string',
            'enabled' => 'bool',
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * The operator seat that verified the rules, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Every staged/refused/published result row for this game.
     *
     * @return HasMany<GameResultImport, $this>
     */
    public function resultImports(): HasMany
    {
        return $this->hasMany(GameResultImport::class, 'game_code', 'code');
    }

    /**
     * Every placement attribution recorded against this game.
     *
     * @return HasMany<GameBetAttribution, $this>
     */
    public function betAttributions(): HasMany
    {
        return $this->hasMany(GameBetAttribution::class, 'game_code', 'code');
    }

    /**
     * Games an operator has switched on.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Games whose rules an operator has verified.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', GameRulesStatus::Verified->value);
    }

    /**
     * Whether the rules of this game are currently trusted.
     *
     * Status alone is not enough: a verification is a statement about a specific
     * configuration block, so a checksum that no longer matches withdraws the
     * trust. A missing stored checksum (a row written without one) also fails
     * closed, because nothing was actually verified.
     *
     * @param  string|null  $currentChecksum  checksum of the live configuration,
     *                                        supplied by GameRegistry (this model
     *                                        never reads configuration)
     */
    public function isVerifiedAgainst(?string $currentChecksum): bool
    {
        if (! $this->status->isVerified()) {
            return false;
        }

        $stored = (string) ($this->rules_checksum ?? '');

        if ($stored === '') {
            return false;
        }

        return is_string($currentChecksum) && hash_equals($stored, $currentChecksum);
    }

    /**
     * Whether the operator has switched the game on AND its rules are verified
     * against the live configuration.
     *
     * This is two of the nine admission gates, not all of them. Sellability is
     * decided by GameAvailabilityService; here the answer is only "the operator
     * half is in order".
     */
    public function isOperationallyReady(?string $currentChecksum): bool
    {
        return $this->enabled === true && $this->isVerifiedAgainst($currentChecksum);
    }

    /**
     * Stamp a verification. Callers must have permission-checked and
     * checksum-checked first; this method only records the fact.
     *
     * @param  array<string, mixed>  $metadata  merged into the recorded metadata
     */
    public function markVerified(int $operatorId, string $checksum, array $metadata = []): void
    {
        $this->forceFill([
            'status' => GameRulesStatus::Verified,
            'rules_checksum' => $checksum,
            'verified_by' => $operatorId,
            'verified_at' => now(),
            'metadata' => array_merge((array) ($this->metadata ?? []), $metadata),
        ])->save();
    }

    /**
     * Withdraw a verification without erasing who gave it or when.
     *
     * The checksum stays on the row on purpose: an auditor must be able to see
     * which rule set was in force while the game was selling.
     */
    public function markSuspended(int $operatorId, ?string $reason = null): void
    {
        $metadata = (array) ($this->metadata ?? []);
        $metadata['suspended_at'] = now()->toIso8601String();
        $metadata['suspended_by'] = $operatorId;
        $metadata['suspension_reason'] = $reason;

        $this->forceFill([
            'status' => GameRulesStatus::Suspended,
            'metadata' => $metadata,
        ])->save();
    }
}
