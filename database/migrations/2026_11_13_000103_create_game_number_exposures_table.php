<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reserved liability per (draw, exact number, market).
 *
 * THE COUNTER THAT MAKES A LIABILITY CAP REAL
 * A multiplier game's risk is concentrated on single numbers, so the cap that
 * matters is per number, not per draw: a thousand spread stakes are fine, four
 * hundred on "0042" at 900x is not. This table is the counter the placement path
 * increments and the cancellation path decrements, one row per combination.
 *
 * UNIQUE(draw_id, number, bet_type) IS THE CONCURRENCY GUARANTEE
 * Two simultaneous placements of the same selection cannot produce two counter
 * rows whatever the interleaving, and the row lock LiabilityExposureService takes
 * inside its transaction then serialises the read-check-increment sequence. The
 * composite index doubles as the exact lookup the placement path performs.
 *
 * `number` IS A STRING
 * "0042" and "42" are different selections. A varchar keeps the leading zeros that
 * a digits column, an int or a numeric type would destroy — and destroying them
 * would merge two different exposures into one counter.
 *
 * TOTALS ARE DECIMAL STRINGS, AGGREGATED IN PHP
 * stake_total and potential_payout_total are only ever changed through bcadd/bcsub
 * at scale 2 inside the service. No float, no SUM() that could round differently
 * on MySQL and SQLite, no generated column.
 *
 * `version` IS AN OPTIMISTIC CHECK, NOT THE CORRECTNESS MECHANISM
 * The row lock is what makes increments safe. The version lets the concurrency
 * test prove the increments were serialised and lets an unexpected writer be
 * detected as stale rather than silently losing an increment.
 *
 * RESTRICT ON DELETE
 * Exposure is financial history attached to a draw. Deleting a draw that still has
 * reserved exposure must fail loudly rather than orphan the counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_number_exposures', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();

            $table->string('game_code', 64);

            // The exact selection, leading zeros intact.
            $table->string('number', 16);

            // The market/bet type the selection belongs to, e.g. 4d_direct.
            $table->string('bet_type', 32);

            // The repo's amount convention: decimal(20,2), currency implicit.
            $table->decimal('stake_total', 20, 2)->default(0);
            $table->decimal('potential_payout_total', 20, 2)->default(0);

            $table->unsignedInteger('version')->default(0);

            $table->timestamps();

            // One counter per selection. See the class docblock.
            $table->unique(['draw_id', 'number', 'bet_type']);

            // The liability screen lists a game's live exposure for a draw.
            $table->index(['game_code', 'draw_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_number_exposures');
    }
};
