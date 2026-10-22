<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LifecycleCampaign;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * One row per (player, campaign) — the campaign idempotency ledger (D4).
 *
 * The unique index on (user_id, campaign) is the actual guarantee; this model
 * exists to give the service a readable way to ask the question and to make
 * the intent obvious at the call site.
 *
 * Rows are never updated after the first insert except to stamp sent_at. There
 * is no "reset so we can send again" path: if a player legitimately qualifies
 * for a second run of the same idea, that is a new campaign name.
 *
 * @property int $id
 * @property int $user_id
 * @property string $campaign
 * @property Carbon|null $sent_at
 * @property string|null $skip_reason
 * @property Carbon $created_at
 */
class LifecycleCampaignSend extends Model
{
    /**
     * Rows are written once by the campaign runner; there is nothing to update
     * after the fact, and disabling timestamps removes a failure mode where a
     * stray save() rewrites created_at and corrupts the run's audit window.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'campaign',
        'sent_at',
        'skip_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
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
     * Claim a (player, campaign) pair.
     *
     * Returns null when the pair is already taken, which the caller treats as
     * "skip". This is firstOrCreate rather than a check-then-insert so the
     * race is resolved by the unique index rather than by luck.
     *
     * @return self|null null when this pair has already been claimed
     */
    public static function claim(int $userId, LifecycleCampaign $campaign): ?self
    {
        try {
            return self::query()->create([
                'user_id' => $userId,
                'campaign' => $campaign->value,
            ]);
        } catch (QueryException $e) {
            // A duplicate is the expected outcome on a concurrent run, not an
            // error. Anything else is a real fault and must surface.
            if (self::isDuplicateError($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Has this player already been through this campaign?
     */
    public static function alreadySent(int $userId, LifecycleCampaign $campaign): bool
    {
        return self::query()
            ->where('user_id', $userId)
            ->where('campaign', $campaign->value)
            ->exists();
    }

    /**
     * @param  list<int>  $userIds
     * @return list<int> the subset of $userIds that has NOT been sent $campaign
     */
    public static function filterUnsent(LifecycleCampaign $campaign, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $seen = self::query()
            ->where('campaign', $campaign->value)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->all();

        return array_values(array_diff($userIds, $seen));
    }

    /**
     * @return Builder<self>
     */
    public function scopeForCampaign(Builder $query, LifecycleCampaign $campaign): Builder
    {
        return $query->where('campaign', $campaign->value);
    }

    private static function isDuplicateError(QueryException $e): bool
    {
        $code = (string) $e->getCode();

        // 1062 MySQL/MariaDB, 23505 PostgreSQL, 19 SQLite unique violation.
        return in_array($code, ['1062', '23505', '19', '23000'], true);
    }
}
