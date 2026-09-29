<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountGame;
use App\Enums\DiscountLottery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * APPEND-ONLY per-game discount snapshot (PROMPT 2, section K/L).
 *
 * One row records what discount WAS APPLIED to one game quote for one
 * user: the grade that applied, the game rule percentage, the explicit
 * eligibility state, the final money figures and BOTH rule versions.
 * Rows are never updated and never rewritten — a corrected evaluation
 * writes a NEW row; history is evidence, not a editable log.
 *
 * MONEY IS DECIMAL STRINGS. Every monetary column is a decimal cast,
 * so a value read from this model is the exact string that was
 * computed; no binary float ever round-trips through a snapshot.
 */
final class GradeDiscountSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
        'lottery',
        'game',
        'mode',
        'grade_key',
        'grade_discount_rate',
        'game_discount_percent',
        'eligibility_state',
        'final_applied_discount',
        'gross_stake',
        'net_stake',
        'game_rule_version',
        'grade_rule_version',
        'effective_at',
        'rule_version',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grade_discount_rate' => 'decimal:4',
            'game_discount_percent' => 'decimal:2',
            'final_applied_discount' => 'decimal:2',
            'gross_stake' => 'decimal:2',
            'net_stake' => 'decimal:2',
            'effective_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * The user whose discount was applied.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The optional subject this snapshot was taken for (a bet, a
     * ticket, a quote...) — nullable morph so snapshots can exist
     * standalone.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The game enum, or null for a historical/unknown key.
     */
    public function gameEnum(): ?DiscountGame
    {
        return DiscountGame::tryFrom((string) $this->game);
    }

    /**
     * The lottery enum, or null for a historical/unknown key.
     */
    public function lotteryEnum(): ?DiscountLottery
    {
        return DiscountLottery::tryFrom((string) $this->lottery);
    }
}
