<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BetSlipStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A member's draft bet slip.
 *
 * THIS MODEL WRITES NOTHING AND MOVES NO MONEY
 * It is a plain Eloquent model. Every state change goes through
 * BetSlipService (the single writer named in Batch3OwnershipTest); the model
 * exposes read helpers and relations only, which is how the ownership test can
 * assert that no controller, resource or read model touches these tables.
 *
 * `total_stake` and `item_count` are DENORMALISED SUMS maintained by
 * BetSlipService inside the same transaction that writes the item. They exist
 * because the slip is rendered on every keystroke in the mockup's UX; they are
 * never the authority — BetSlipPricingService recomputes the total from the
 * items and a test asserts the two agree.
 *
 * @property int $id
 * @property int $member_id
 * @property string $reference
 * @property BetSlipStatus $status
 * @property string $currency
 * @property string $total_stake
 * @property int $item_count
 * @property Carbon|null $expires_at
 * @property Carbon|null $confirmed_at
 * @property string|null $confirmed_reference
 * @property string|null $confirmation_key
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BetSlip extends Model
{
    /**
     * Fields a caller may mass-assign. Status, totals and the confirmation
     * stamps are deliberately absent: they are written by BetSlipService with
     * explicit assignment after its own checks, so no request payload can
     * arrive here carrying a `status` or a `total_stake`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'member_id',
        'reference',
        'currency',
        'expires_at',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BetSlipStatus::class,
            'total_stake' => 'decimal:2',
            'item_count' => 'integer',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'meta' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    /**
     * @return HasMany<BetSlipItem, self>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BetSlipItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The member's current editable slip, if one exists.
     *
     * Scoped by member_id as well as status so this scope can never be used to
     * read somebody else's draft by accident.
     */
    public function scopeDraftFor(Builder $query, int $memberId): Builder
    {
        return $query->where('member_id', $memberId)->where('status', BetSlipStatus::Draft->value);
    }

    public function scopeOfStatus(Builder $query, BetSlipStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * Drafts whose clock has run out. Used by ExpireBetSlipsJob; a draft can
     * still carry status='draft' while expires_at is in the past, which is why
     * expiry is a time fact and not only a status fact.
     */
    public function scopeExpiredByTime(Builder $query, ?Carbon $at = null): Builder
    {
        return $query
            ->where('status', BetSlipStatus::Draft->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $at ?? Carbon::now());
    }

    /**
     * Whether the TTL has elapsed. A slip with no expiry never expires by time;
     * it still expires when its draw closes, which the confirmation service
     * checks against the draw, not against this row.
     */
    public function hasExpired(?Carbon $at = null): bool
    {
        if ($this->status === BetSlipStatus::Expired) {
            return true;
        }

        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lessThanOrEqualTo($at ?? Carbon::now());
    }

    /**
     * Editable = still a draft AND not past its clock. Both halves are needed:
     * a job may not have swept the expiry yet.
     */
    public function isEditable(?Carbon $at = null): bool
    {
        return $this->status->isEditable() && ! $this->hasExpired($at);
    }

    public function isEmpty(): bool
    {
        return $this->item_count === 0;
    }
}
