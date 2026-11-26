<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bet slip items — one intended selection inside a draft slip.
 *
 * THE NUMBER AND THE STAKE ARE STRINGS
 * `number` is a fixed-width digit string ('007' is not 7) and `stake` is an
 * exact decimal. Neither is ever cast to int or float anywhere in Batch 3:
 * the column types below are the same ones the existing betting pipeline
 * uses, so a number that survives the slip survives validation unchanged.
 *
 * PRICING IS NOT STORED HERE
 * `potential_payout` is deliberately absent. A draft price is a display value
 * computed at read time by BetSlipPricingService from the live draw and the
 * configured multiplier; persisting it would freeze a number that the draw
 * can invalidate while the member is still editing the slip.
 *
 * `game_code` is the GameAvailabilityService key (or the GLO L6 / PCSO path
 * key). A code that the availability service has never heard of cannot be
 * persisted: the writer validates before insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bet_slip_items')) {
            return;
        }

        Schema::create('bet_slip_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('bet_slip_id')
                ->constrained('bet_slips')
                ->cascadeOnDelete();

            // The sellable game key, resolved against the game availability
            // registry at write time.
            $table->string('game_code', 40);

            // The market family within that game, e.g. '3d_direct'.
            $table->string('bet_type', 24);

            // Digits only, leading zeros preserved, length checked by the
            // DigitNumberValidator-equivalent rule set in the service.
            $table->string('number', 12);

            // DECIMAL, not float. Digits as strings end-to-end.
            $table->decimal('stake', 20, 2);

            // Where the money would come from at confirm time. 'wallet' is the
            // default; the other values exist only if the corresponding
            // Batch-2 funding adapter exists (see the gap table).
            $table->string('funding_source', 24)->default('wallet');

            // A free-bet voucher reference, nullable. Batch 3 does NOT create a
            // free-bet store: if Batch 2 has no adapter, the service refuses
            // the item rather than inventing one.
            $table->string('free_bet_voucher_reference', 64)->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['bet_slip_id', 'position'], 'bet_slip_items_position_idx');

            // The same number twice in the same market in one draft is a
            // legitimate thing a player does across DIFFERENT bets, but inside
            // a single draft slip it is a double-tap artefact. The service
            // merges instead of duplicating; this index makes a stray duplicate
            // impossible at the storage layer too.
            $table->unique(
                ['bet_slip_id', 'game_code', 'bet_type', 'number'],
                'bet_slip_items_unique_selection'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bet_slip_items');
    }
};
