<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CashbackAccrualStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id
 * @property int $policy_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $basis_amount
 * @property string $accrual_amount
 * @property CashbackAccrualStatus $status
 * @property Carbon|null $expires_at
 * @property Carbon|null $claimed_at
 * @property int|null $claim_transaction_id
 * @property Carbon|null $reversed_at
 * @property string|null $reversal_reason
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CashbackAccrual extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'member_id',
        'policy_id',
        'period_start',
        'period_end',
        'basis_amount',
        'accrual_amount',
        'status',
        'expires_at',
        'claimed_at',
        'claim_transaction_id',
        'reversed_at',
        'reversal_reason',
        'clawback_amount',
        'clawback_shortfall',
        'clawback_transaction_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'basis_amount' => 'decimal:2',
            'accrual_amount' => 'decimal:2',
            'status' => CashbackAccrualStatus::class,
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'reversed_at' => 'datetime',
            'clawback_amount' => 'decimal:2',
            'clawback_shortfall' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeClaimable(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $pointInTime = $now ?? Carbon::now();

        return $query
            ->where('status', CashbackAccrualStatus::Accrued->value)
            ->where(function (Builder $sub) use ($pointInTime): void {
                $sub->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $pointInTime);
            });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForPeriod(Builder $query, CarbonInterface $periodStart, CarbonInterface $periodEnd): Builder
    {
        return $query
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd);
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    /**
     * @return BelongsTo<CashbackPolicy, self>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(CashbackPolicy::class, 'policy_id');
    }

    /**
     * @return BelongsTo<FinancialTransaction, self>
     */
    public function claimTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'claim_transaction_id');
    }
}
