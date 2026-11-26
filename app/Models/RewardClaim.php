<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RewardClaimStatus;
use App\Enums\RewardType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id
 * @property int $item_id
 * @property int $points_spent
 * @property RewardClaimStatus $status
 * @property string $idempotency_key
 * @property int|null $loyalty_ledger_id
 * @property int|null $financial_transaction_id
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RewardClaim extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'member_id',
        'item_id',
        'points_spent',
        'status',
        'idempotency_key',
        'loyalty_ledger_id',
        'financial_transaction_id',
        'payload',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points_spent' => 'integer',
            'status' => RewardClaimStatus::class,
            'payload' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Active cashback boosts for a member at a given instant.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActiveCashbackBoosts(Builder $query, User|int $member, ?CarbonInterface $now = null): Builder
    {
        $memberId = $member instanceof User ? (int) $member->getKey() : $member;
        $pointInTime = ($now ?? Carbon::now())->toImmutable();

        $iso = $pointInTime->toIso8601String();

        return $query
            ->where('member_id', $memberId)
            ->where('status', RewardClaimStatus::Completed->value)
            ->whereHas('item', function (Builder $itemQuery): void {
                $itemQuery->where('type', RewardType::CashbackBoost->value);
            })
            ->where(function (Builder $sub) use ($iso): void {
                $sub->whereNull('payload->boost_starts_at')
                    ->orWhere('payload->boost_starts_at', '<=', $iso);
            })
            ->where(function (Builder $sub) use ($iso): void {
                $sub->whereNull('payload->boost_ends_at')
                    ->orWhere('payload->boost_ends_at', '>', $iso);
            });
    }

    public function isBoostActiveAt(?CarbonInterface $now = null): bool
    {
        if ($this->status !== RewardClaimStatus::Completed) {
            return false;
        }

        $pointInTime = $now ?? Carbon::now();
        $payload = is_array($this->payload) ? $this->payload : [];

        $startsAtRaw = $payload['boost_starts_at'] ?? null;
        if (is_string($startsAtRaw) && $startsAtRaw !== '' && Carbon::parse($startsAtRaw)->gt($pointInTime)) {
            return false;
        }

        $endsAtRaw = $payload['boost_ends_at'] ?? null;
        if (is_string($endsAtRaw) && $endsAtRaw !== '' && Carbon::parse($endsAtRaw)->lte($pointInTime)) {
            return false;
        }

        return $this->extraBoostBps() > 0;
    }

    public function extraBoostBps(): int
    {
        $payload = is_array($this->payload) ? $this->payload : [];

        return max(0, (int) ($payload['extra_bps'] ?? 0));
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    /**
     * @return BelongsTo<RewardCatalogItem, self>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(RewardCatalogItem::class, 'item_id');
    }

    /**
     * @return HasOne<FreeBetVoucher, self>
     */
    public function voucher(): HasOne
    {
        return $this->hasOne(FreeBetVoucher::class, 'claim_id');
    }

    /**
     * @return BelongsTo<LoyaltyPointLedger, self>
     */
    public function loyaltyLedger(): BelongsTo
    {
        return $this->belongsTo(LoyaltyPointLedger::class, 'loyalty_ledger_id');
    }

    /**
     * @return BelongsTo<FinancialTransaction, self>
     */
    public function financialTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'financial_transaction_id');
    }
}
