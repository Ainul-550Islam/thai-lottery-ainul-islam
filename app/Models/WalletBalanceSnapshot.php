<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One day's captured balance for one wallet of one member.
 *
 * A SIDE-CAR, NOT A SOURCE OF TRUTH
 * The authoritative balance is wallets.balance. This row is a photograph taken
 * by WalletBalanceSnapshotService so that "up N% since last week" can be
 * answered without replaying a week of ledger entries. If the two ever disagree,
 * the bank-style rule applies: the ledger wins, and the snapshot is rebuilt.
 * LedgerIntegrityChecker never reads this table.
 *
 * `balance` is decimal:2 and therefore a STRING in PHP, which keeps the trend
 * calculation in bcmath. A trend computed with floats would show 5.200000001%
 * on amounts where the true answer is exactly 5.2%.
 *
 * @property int $id
 * @property int $member_id
 * @property string $wallet_type
 * @property string $currency
 * @property string $balance
 * @property string $locked_balance
 * @property Carbon $snapshot_date
 * @property Carbon $captured_at
 */
class WalletBalanceSnapshot extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'member_id',
        'wallet_type',
        'currency',
        'balance',
        'locked_balance',
        'snapshot_date',
        'captured_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'wallet_type' => WalletType::class,
            'balance' => 'decimal:2',
            'locked_balance' => 'decimal:2',
            'snapshot_date' => 'date',
            'captured_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function scopeForMember(Builder $query, int $memberId): Builder
    {
        return $query->where('member_id', $memberId);
    }

    public function scopeOfType(Builder $query, WalletType $type): Builder
    {
        return $query->where('wallet_type', $type->value);
    }

    /**
     * The newest snapshot at or before the given day.
     *
     * <= rather than == because the snapshot job can miss a day (a deploy, an
     * outage). Comparing against "the last known balance before the horizon"
     * is honest about a gap; pretending there was no balance would return null
     * and hide a real trend.
     */
    public function scopeAtOrBefore(Builder $query, Carbon $day): Builder
    {
        return $query->where('snapshot_date', '<=', $day->toDateString());
    }

    public function exactBalance(): string
    {
        return (string) $this->balance;
    }
}
