<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * THE OPERATOR'S RESERVED LIABILITY FOR ONE EXACT NUMBER ON ONE DRAW.
 *
 * THE PROBLEM IT SOLVES
 * A digit game pays a multiplier on a number. If 900x is offered and a hundred
 * players take "0042" on the same draw, the operator owes 90,000 times the stake
 * on a single outcome. The existing Batch-1 number limits are scoped to the
 * Batch-1 markets and keyed by int numbers, so they cannot express "the string
 * 0042 of the 4d_direct market of draw 17". This table can.
 *
 * WHY AGGREGATED AND NOT PER-BET
 * A per-bet row would be a copy of the bets table with none of its guarantees.
 * What the placement path actually needs is one atomically incrementable counter
 * per (draw, number, market) plus a version for optimistic conflict detection,
 * so the check "would this bet breach the cap?" is a single locked row read.
 *
 * THE UNIQUE KEY IS THE CONCURRENCY CONTROL
 * UNIQUE(draw_id, number, bet_type) means two placements of the same number can
 * never create two counter rows, whatever the interleaving. Together with the
 * row lock taken by LiabilityExposureService inside its transaction, that makes
 * both "is the cap breached?" and "reserve the exposure" correct under
 * concurrency; the composite index is also the exact lookup the placement path
 * performs.
 *
 * `version` IS OPTIMISTIC AND DELIBERATELY REDUNDANT WITH THE LOCK
 * The lock is the correctness mechanism. The version exists so an unexpected
 * write path (a report, a manual reconciliation) can be detected as stale rather
 * than silently losing an increment, and so the concurrency test can assert that
 * increments were serialised.
 *
 * STRINGS, NOT MONEY TYPES
 * `number` is a string because "0042" and "42" are different selections and a
 * leading zero that survives placement must survive here too. The two totals are
 * decimal strings in the repo's existing amount convention and are only ever
 * touched through bcadd/bcsub with scale 2 — never cast to float, never summed by
 * the database.
 *
 * THIS MODEL DOES NOT DECIDE ANYTHING
 * It stores counters. Whether a reservation may be made, and how the cap is
 * read, is LiabilityExposureService's job.
 *
 * @property int $id
 * @property int $draw_id
 * @property string $game_code
 * @property string $number
 * @property string $bet_type
 * @property string $stake_total
 * @property string $potential_payout_total
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GameNumberExposure extends Model
{
    protected $fillable = [
        'draw_id',
        'game_code',
        'number',
        'bet_type',
        'stake_total',
        'potential_payout_total',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'draw_id' => 'integer',
            'stake_total' => 'string',
            'potential_payout_total' => 'string',
            'version' => 'integer',
        ];
    }

    /**
     * The draw this exposure belongs to.
     *
     * @return BelongsTo<Draw, $this>
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class, 'draw_id');
    }

    /**
     * The reserved stake as a scale-2 decimal string, normalised through bcadd so
     * a driver that hands back an int or a float cannot leak that type upward.
     */
    public function stakeTotal(): string
    {
        return bcadd((string) $this->stake_total, '0', 2);
    }

    /**
     * The reserved potential payout as a scale-2 decimal string.
     */
    public function potentialPayoutTotal(): string
    {
        return bcadd((string) $this->potential_payout_total, '0', 2);
    }

    /**
     * Whether this number has any live exposure at all.
     *
     * Rows are removed rather than left at zero when the last reservation is
     * released, so a zero row is an anomaly, not a normal state; both readings
     * answer "no liability" and the caller treats them identically.
     */
    public function isEmpty(): bool
    {
        return bccomp($this->stakeTotal(), '0', 2) <= 0
            && bccomp($this->potentialPayoutTotal(), '0', 2) <= 0;
    }

    /**
     * The human readable identity of this counter, for logs and the liability
     * screen: game, draw, market, number.
     */
    public function describe(): string
    {
        return $this->game_code.'/draw#'.$this->draw_id.'/'.$this->bet_type.'/'.$this->number;
    }
}
