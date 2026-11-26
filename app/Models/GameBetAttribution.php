<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BetFundingSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * WHY ONE BET EXISTS, IN THE TERMS OF THE DIGIT-GAME LANE.
 *
 * THE CONSTRAINT THAT FORCED THIS TABLE
 * Batch 1 owns the bets table. Its ownership proof hashes bets.metadata, so a
 * batch-2 feature that started stamping game_code, game_number, game_bet_type and
 * game_potential_payout into that JSON column would change the hash of rows it
 * does not own and break an existing, deliberate guarantee. Rule §0.1 says edits
 * to existing logic are additive only, and Rule §0.2 says single ownership. The
 * resolution is the one used everywhere else in this batch: put the new facts in
 * a new table and let the old table keep meaning exactly what it meant.
 *
 * So bets gains five NULLABLE columns (game_code, game_number, game_bet_type,
 * funding_source, free_bet_voucher_id) that are cheap, indexable and non-secret,
 * and everything that describes the placement — the market key, the reserved
 * potential payout, the idempotency key that produced it — lives here, one row
 * per game bet, at most.
 *
 * ONE ROW PER BET, ENFORCED
 * UNIQUE(bet_id) with a restrictOnDelete foreign key. A bet is financial history:
 * it is never hard-deleted, and if it were, the attribution would refuse to lose
 * its parent silently.
 *
 * WHY potential_payout IS STORED
 * The payout reserved at placement is what was debited against the number's
 * liability cap, computed from the multiplier that was in force at that moment.
 * Recomputing it later from the CURRENT configuration would silently re-price a
 * historical bet every time an operator edits a multiplier, and the exposure
 * release on cancellation (ReleaseExposureOnBetCancelled) would then release a
 * different amount than it reserved. So the amount is frozen here, exactly like
 * bets.potential_payout is frozen on the Batch-1 side.
 *
 * THIS MODEL NEVER TOUCHES MONEY
 * No wallet, no ledger, no balance. `stake` and `potential_payout` are
 * descriptions of a bet that already exists in the Batch-1 purchase path.
 *
 * @property int $id
 * @property int $bet_id
 * @property int|null $draw_id
 * @property string $game_code
 * @property string $bet_type
 * @property string $number
 * @property string $stake
 * @property string $potential_payout
 * @property string|null $market_key
 * @property BetFundingSource $funding_source
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<string, mixed>|null $metadata
 */
class GameBetAttribution extends Model
{
    protected $fillable = [
        'bet_id',
        'draw_id',
        'game_code',
        'bet_type',
        'number',
        'stake',
        'potential_payout',
        'market_key',
        'funding_source',
        'idempotency_key',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bet_id' => 'integer',
            'draw_id' => 'integer',
            'stake' => 'string',
            'potential_payout' => 'string',
            'funding_source' => BetFundingSource::class,
            'metadata' => 'array',
        ];
    }

    /**
     * The Batch-1 bet this row describes.
     *
     * @return BelongsTo<Bet, $this>
     */
    public function bet(): BelongsTo
    {
        return $this->belongsTo(Bet::class, 'bet_id');
    }

    /**
     * The draw the bet was placed on, when it is known at placement time.
     *
     * @return BelongsTo<Draw, $this>
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class, 'draw_id');
    }

    /**
     * The reserved potential payout as a scale-2 decimal string.
     *
     * This is the value the exposure release must give back, and the value the
     * audit report must display. bcadd normalises whatever the driver returned.
     */
    public function payout(): string
    {
        return bcadd((string) $this->potential_payout, '0', 2);
    }

    /**
     * The stake as a scale-2 decimal string.
     */
    public function stakeAmount(): string
    {
        return bcadd((string) $this->stake, '0', 2);
    }

    /**
     * Whether this attribution identifies a complete selection.
     *
     * A bet whose number or market is unknown cannot have its exposure released,
     * because releasing "something" would drift the counter. The release listener
     * asks this question and logs a skip instead of guessing.
     */
    public function identifiesSelection(): bool
    {
        return trim((string) $this->number) !== '' && trim((string) $this->bet_type) !== '';
    }
}
