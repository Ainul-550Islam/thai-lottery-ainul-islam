<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHY A GAME BET EXISTS, WITHOUT TOUCHING THE BATCH-1 OWNERSHIP PROOF.
 *
 * Batch 1 hashes bets.metadata into an ownership proof. A batch-2 lane that
 * stamped game_code, game_number, game_bet_type and the reserved payout into that
 * JSON would change the hash of rows and a contract it does not own. So the
 * descriptive facts live here, in a new table, and bets only gains five cheap,
 * nullable, indexable columns (see xxxx_add_game_fields_to_bets_table).
 *
 * WHAT LIVES HERE AND WHY EACH COLUMN EARNED ITS PLACE
 *   bet_id            UNIQUE, restrictOnDelete. One attribution per bet, and
 *                     financial history never loses its parent silently.
 *   draw_id           nullable, so an attribution can be written before the draw
 *                     is resolved; restrictOnDelete for the same reason as the
 *                     exposure table.
 *   game_code         which game — the join key to lottery_games.code.
 *   bet_type          the market, e.g. 4d_direct. Together with `number` it is
 *                     the exact triple the exposure counter is keyed by, so a
 *                     cancellation can release precisely what placement reserved.
 *   number            the selection as a STRING with leading zeros intact.
 *   stake             the stake in the repo's amount convention.
 *   potential_payout  the payout reserved AT PLACEMENT TIME.
 *   market_key        the config('lottery.markets') key the bet settles through,
 *                     so settlement reuses the existing resolver instead of a
 *                     second payout path.
 *   funding_source    real|bonus|free_bet — App\Enums\BetFundingSource.
 *   idempotency_key   the key that produced the placement, so a replay can be
 *                     traced from the attribution alone.
 *   metadata          free-form operational annotations, never a rule value.
 *
 * WHY potential_payout IS FROZEN HERE
 * It is the amount that was debited against the number's liability cap using the
 * multiplier in force at that moment. Recomputing it from current configuration
 * would silently re-price a historical bet whenever an operator edits a
 * multiplier, and the cancellation release would then give back a different
 * amount than was reserved. bets.potential_payout is frozen on the Batch-1 side
 * for exactly the same reason; this column follows that precedent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_bet_attributions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('bet_id')->unique()->constrained('bets')->restrictOnDelete();

            $table->foreignId('draw_id')->nullable()->constrained('draws')->restrictOnDelete();

            $table->string('game_code', 64)->index();
            $table->string('bet_type', 32);
            $table->string('number', 16);

            $table->decimal('stake', 20, 2);
            $table->decimal('potential_payout', 20, 2)->default(0);

            $table->string('market_key', 64)->nullable();

            // real | bonus | free_bet. Never null on a new row: the placement path
            // always names its source. Nullable only so a backfill can be staged.
            $table->string('funding_source', 16)->nullable()->index();

            // The placement key, for tracing a replay back to the batch-2 caller.
            $table->string('idempotency_key', 128)->nullable()->index();

            $table->json('metadata')->nullable();

            $table->timestamps();

            // The cancellation release looks a bet up by its selection triple.
            $table->index(['draw_id', 'bet_type', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_bet_attributions');
    }
};
