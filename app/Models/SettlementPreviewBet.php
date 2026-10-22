<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bet's rehearsed outcome inside a settlement preview.
 *
 * Records the obligation settlement WOULD create for this bet — selections
 * evaluated, winners found, exact stake and simulated prize — so an operator
 * can reconcile the rehearsal against the later real run. Writing this row
 * is the only write a preview ever makes for a bet.
 */
class SettlementPreviewBet extends Model
{
    protected $table = 'settlement_preview_bets';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selections_evaluated' => 'integer',
            'winning_selections' => 'integer',
            'stake' => 'decimal:2',
            'simulated_prize' => 'decimal:2',
            'is_winner' => 'boolean',
        ];
    }

    public function preview(): BelongsTo
    {
        return $this->belongsTo(SettlementPreview::class, 'settlement_preview_id');
    }
}
