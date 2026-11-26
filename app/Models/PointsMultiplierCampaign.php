<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A window during which points earned are multiplied.
 *
 * BASIS POINTS
 * `multiplier_bps` is an unsigned integer: 10000 = 1.0x, 15000 = 1.5x. The
 * points ledger is integer-valued, so keeping the multiplier integer means the
 * only rounding decision in the whole lane is the floor applied once in
 * PointsMultiplierService. Bps also makes the configurable ceiling
 * (config('player_experience.loyalty.max_multiplier_bps')) expressible as an
 * integer comparison rather than a float one.
 *
 * THE WINDOW IS INCLUSIVE ON BOTH ENDS
 * scopeActiveAt uses `starts_at <= t AND (ends_at IS NULL OR ends_at >= t)`,
 * and PointsMultiplierTest asserts the exact boundary second. An exclusive end
 * would silently drop the final second of a campaign; an exclusive start would
 * drop the first. Neither is a rounding detail a member would ever be told
 * about, so both are pinned by a test.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $multiplier_bps
 * @property string|null $game_scope
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property bool $active
 * @property string|null $tier_scope
 * @property int|null $created_by_user_id
 * @property string|null $description
 * @property array<string, mixed>|null $meta
 */
class PointsMultiplierCampaign extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'multiplier_bps',
        'game_scope',
        'starts_at',
        'ends_at',
        'active',
        'tier_scope',
        'created_by_user_id',
        'description',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'multiplier_bps' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'active' => 'boolean',
            'meta' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Campaigns that are running at the given moment.
     *
     * `active` is checked here as well as in the service: a soft-disabled
     * campaign must not be revived by a clock window that happens to match.
     */
    public function scopeActiveAt(Builder $query, ?Carbon $at = null): Builder
    {
        $moment = $at ?? Carbon::now();

        return $query
            ->where('active', true)
            ->where('starts_at', '<=', $moment)
            ->where(function (Builder $inner) use ($moment): void {
                $inner->whereNull('ends_at')->orWhere('ends_at', '>=', $moment);
            });
    }

    /**
     * Scope to one game, or to campaigns that apply to every game.
     *
     * A game-scoped campaign and an all-games campaign can both be live; the
     * service picks the highest, and this scope is how it asks "which ones even
     * apply to this game".
     */
    public function scopeForGame(Builder $query, ?string $gameCode): Builder
    {
        if ($gameCode === null) {
            return $query->whereNull('game_scope');
        }

        return $query->where(function (Builder $inner) use ($gameCode): void {
            $inner->whereNull('game_scope')->orWhere('game_scope', $gameCode);
        });
    }

    public function scopeForTier(Builder $query, ?string $tierKey): Builder
    {
        if ($tierKey === null) {
            return $query->whereNull('tier_scope');
        }

        return $query->where(function (Builder $inner) use ($tierKey): void {
            $inner->whereNull('tier_scope')->orWhere('tier_scope', $tierKey);
        });
    }

    public function isActiveAt(?Carbon $at = null): bool
    {
        $moment = $at ?? Carbon::now();

        if (! $this->active) {
            return false;
        }

        if ($this->starts_at->greaterThan($moment)) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->greaterThanOrEqualTo($moment);
    }

    /**
     * The multiplier as an exact bcmath-friendly decimal STRING ('1.5000').
     * Nothing in the lane multiplies with a float.
     */
    public function multiplierDecimal(): string
    {
        return bcdiv((string) $this->multiplier_bps, '10000', 4);
    }

    /**
     * Whether this campaign overlaps another in time. Used by the Filament
     * form to WARN (not to block): two overlapping campaigns are legal under
     * the highest-only rule, but an operator almost always meant to edit.
     */
    public function overlaps(self $other): bool
    {
        $thisStart = $this->starts_at;
        $thisEnd = $this->ends_at;
        $otherStart = $other->starts_at;
        $otherEnd = $other->ends_at;

        if ($thisEnd !== null && $thisEnd->lessThan($otherStart)) {
            return false;
        }

        if ($otherEnd !== null && $otherEnd->lessThan($thisStart)) {
            return false;
        }

        return true;
    }
}
