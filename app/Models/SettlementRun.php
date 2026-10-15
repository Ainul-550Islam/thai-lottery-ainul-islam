<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Progress record for one chunked settlement run over one draw.
 *
 * This model is NOT the money. It records how far a settlement got and what it
 * has totalled so far, so a run interrupted at slip 99,000 resumes at slip
 * 99,001 instead of starting again. The money is in `payouts`,
 * `financial_transactions` and the ledger — where it has always been.
 *
 * @property int $id
 * @property int $draw_id
 * @property string $status
 * @property int $chunk_size
 * @property int $cursor_bet_id
 * @property int $bets_total
 * @property int $bets_settled
 * @property int $selections_evaluated
 * @property int $selections_written
 * @property int $winning_selections
 * @property int $payouts_created
 * @property string $total_stake
 * @property string $total_prize
 * @property string|null $currency
 * @property string $mode
 * @property string|null $safety_mode
 * @property int $chunks_processed
 * @property Carbon $started_at
 * @property Carbon|null $last_chunk_at
 * @property Carbon|null $completed_at
 * @property int|null $started_by
 * @property string|null $failure_reason
 */
class SettlementRun extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABORTED = 'aborted';

    protected $table = 'settlement_runs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'draw_id',
        'status',
        'chunk_size',
        'cursor_bet_id',
        'bets_total',
        'bets_settled',
        'selections_evaluated',
        'selections_written',
        'winning_selections',
        'payouts_created',
        'total_stake',
        'total_prize',
        'currency',
        'mode',
        'safety_mode',
        'chunks_processed',
        'started_at',
        'last_chunk_at',
        'completed_at',
        'started_by',
        'failure_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunk_size' => 'integer',
            'cursor_bet_id' => 'integer',
            'bets_total' => 'integer',
            'bets_settled' => 'integer',
            'selections_evaluated' => 'integer',
            'selections_written' => 'integer',
            'winning_selections' => 'integer',
            'payouts_created' => 'integer',
            'total_stake' => 'decimal:2',
            'total_prize' => 'decimal:2',
            'chunks_processed' => 'integer',
            'started_at' => 'datetime',
            'last_chunk_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * Runs still able to accept chunks.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInFlight(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_ABORTED]);
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * Sums recorded so far, as decimal strings, for reporting.
     *
     * @return array<string, string|int>
     */
    public function totals(): array
    {
        return [
            'bets_total' => $this->bets_total,
            'bets_settled' => $this->bets_settled,
            'selections_evaluated' => $this->selections_evaluated,
            'selections_written' => $this->selections_written,
            'winning_selections' => $this->winning_selections,
            'payouts_created' => $this->payouts_created,
            'total_stake' => (string) $this->total_stake,
            'total_prize' => (string) $this->total_prize,
            'currency' => (string) ($this->currency ?? ''),
        ];
    }

    /**
     * How far through the draw this run is, as a percentage.
     *
     * Returns null when the denominator is unknown (a run opened before bets were
     * counted). Reporting "0%" for "unknown" would be a lie an operator would
     * reasonably act on.
     */
    public function percentComplete(): ?float
    {
        if ($this->bets_total < 1) {
            return null;
        }

        return round(min(100.0, ($this->bets_settled / $this->bets_total) * 100), 2);
    }
}
