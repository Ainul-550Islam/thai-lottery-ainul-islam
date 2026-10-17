<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One read-only settlement rehearsal over one draw.
 *
 * A preview prices a published draw exactly as settlement would and records
 * what WOULD be owed, in these preview tables only. It is not the money and
 * it never becomes the money: there is deliberately no code path from this
 * row to a wallet, a ledger entry, or a draw transition.
 */
class SettlementPreview extends Model
{
    /** The only mode a preview can ever run in. */
    public const MODE = 'DRY_RUN';

    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    protected $table = 'settlement_previews';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunk_size' => 'integer',
            'cursor_bet_id' => 'integer',
            'bets_total' => 'integer',
            'bets_processed' => 'integer',
            'chunks_processed' => 'integer',
            'selections_evaluated' => 'integer',
            'winning_selections' => 'integer',
            'total_stake' => 'decimal:2',
            'total_prize' => 'decimal:2',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function previewBets(): HasMany
    {
        return $this->hasMany(SettlementPreviewBet::class, 'settlement_preview_id');
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
