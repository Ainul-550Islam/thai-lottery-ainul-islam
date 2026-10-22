<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only settlement preview (rehearsal) tables.
 *
 * A preview prices a published draw exactly as settlement would and records
 * what WOULD be owed — in these two tables and nowhere else. No wallet, no
 * ledger, no obligation row is ever written by a preview, and no code path
 * leads from these tables to one: the rehearsal exists so an operator can
 * reconcile "what the run says it will do" against "what the run did",
 * before any money moves.
 *
 * The draw and bet references are plain indexed integers, deliberately not
 * foreign keys into the live tables: a preview is an audit artefact that
 * must survive independently, and a rehearsal must never be able to block
 * (or be blocked by) lifecycle operations on the money tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_previews', function (Blueprint $table): void {
            $table->id();

            // The draw being rehearsed (plain reference; see the note above).
            $table->unsignedBigInteger('draw_id')->index();

            // Always DRY_RUN. Recorded rather than assumed, so a summary can
            // state the mode the numbers were produced under.
            $table->string('mode', 16)->default('DRY_RUN');

            $table->string('status', 16)->default('open')->index();

            $table->unsignedInteger('chunk_size')->default(500);

            // Keyset cursor: the next chunk reads bets with id greater than
            // this, so a crash resumes exactly where the rehearsal stopped.
            $table->unsignedBigInteger('cursor_bet_id')->default(0);

            $table->unsignedBigInteger('bets_total')->default(0);
            $table->unsignedBigInteger('bets_processed')->default(0);
            $table->unsignedInteger('chunks_processed')->default(0);
            $table->unsignedBigInteger('selections_evaluated')->default(0);
            $table->unsignedBigInteger('winning_selections')->default(0);

            // Exact decimal totals (bcmath domain, never binary floating point).
            $table->decimal('total_stake', 20, 2)->default('0');
            $table->decimal('total_prize', 20, 2)->default('0');
            $table->string('currency', 8)->nullable();

            // Hash of the pricing rules in force at open. A chunk that finds
            // a different fingerprint refuses rather than mixing two rule sets
            // into one rehearsal total.
            $table->string('rules_fingerprint', 64);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });

        Schema::create('settlement_preview_bets', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('settlement_preview_id')->index();

            // The bet that was rehearsed (plain reference; see the note above).
            $table->unsignedBigInteger('bet_id');
            $table->unsignedBigInteger('user_id')->nullable();

            $table->unsignedInteger('selections_evaluated')->default(0);
            $table->unsignedInteger('winning_selections')->default(0);
            $table->boolean('is_winner')->default(false);

            $table->decimal('stake', 20, 2)->default('0');
            $table->decimal('simulated_prize', 20, 2)->default('0');
            $table->string('currency', 8)->nullable();

            $table->timestamps();

            // One rehearsed row per bet per preview: a re-run chunk updates
            // the same row instead of double-counting the obligation.
            $table->unique(['settlement_preview_id', 'bet_id'], 'settlement_preview_bets_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_preview_bets');
        Schema::dropIfExists('settlement_previews');
    }
};
