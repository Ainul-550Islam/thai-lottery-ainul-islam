<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FreeBetVoucherStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id
 * @property int|null $claim_id
 * @property string $code
 * @property string $currency
 * @property string $amount
 * @property FreeBetVoucherStatus $status
 * @property Carbon $expires_at
 * @property int|null $consumed_bet_id
 * @property Carbon|null $consumed_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FreeBetVoucher extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'member_id',
        'claim_id',
        'code',
        'currency',
        'amount',
        'status',
        'expires_at',
        'consumed_bet_id',
        'consumed_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => FreeBetVoucherStatus::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $pointInTime = $now ?? Carbon::now();

        return $query
            ->where('status', FreeBetVoucherStatus::Active->value)
            ->whereNull('consumed_bet_id')
            ->where('expires_at', '>', $pointInTime);
    }

    public function isUsable(?CarbonInterface $now = null): bool
    {
        $pointInTime = $now ?? Carbon::now();

        return $this->status === FreeBetVoucherStatus::Active
            && $this->consumed_bet_id === null
            && $this->expires_at->gt($pointInTime);
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    /**
     * @return BelongsTo<RewardClaim, self>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(RewardClaim::class, 'claim_id');
    }

    /**
     * @return BelongsTo<Bet, self>
     */
    public function consumedBet(): BelongsTo
    {
        return $this->belongsTo(Bet::class, 'consumed_bet_id');
    }
}
