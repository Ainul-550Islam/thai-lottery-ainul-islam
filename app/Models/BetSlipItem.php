<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One intended selection inside a draft slip.
 *
 * NUMBER AND STAKE ARE STRINGS, ALWAYS
 * `number` is the digit string exactly as the member entered it, zeros and all.
 * `stake` is cast to 'decimal:2', which Laravel hands back as a STRING, never a
 * float. Batch 3 performs no arithmetic on either value here: the model is a
 * carrier, BetSlipPricingService prices, and the existing betting pipeline
 * validates.
 *
 * WHAT IS DELIBERATELY ABSENT
 * No potential_payout (a live price, not a stored fact), no bet_id (the item is
 * a draft; the bet it becomes is created by the purchase pipeline and linked
 * only through the slip's confirmed_reference), no wallet and no status.
 *
 * @property int $id
 * @property int $bet_slip_id
 * @property string $game_code
 * @property string $bet_type
 * @property string $number
 * @property string $stake
 * @property string $funding_source
 * @property string|null $free_bet_voucher_reference
 * @property int $position
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BetSlipItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'bet_slip_id',
        'game_code',
        'bet_type',
        'number',
        'stake',
        'funding_source',
        'free_bet_voucher_reference',
        'position',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // 'decimal:2' yields a STRING, which is the point: no float.
            'stake' => 'decimal:2',
            'position' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BetSlip, self>
     */
    public function slip(): BelongsTo
    {
        return $this->belongsTo(BetSlip::class, 'bet_slip_id');
    }

    /**
     * The stake as the exact string bcmath will consume. Declared as a method
     * rather than a magic attribute so a reader sees that no conversion to
     * float happens between the database and the pricer.
     */
    public function exactStake(): string
    {
        return (string) $this->stake;
    }

    /**
     * Whether this item is meant to be funded by something other than the
     * wallet. Used by the confirmation service to decide whether a funding
     * adapter is required — and, when none exists, to refuse the item instead
     * of charging the wallet for it.
     */
    public function requiresFundingAdapter(): bool
    {
        return $this->funding_source !== 'wallet';
    }
}
