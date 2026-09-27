<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bingo_lottery_draws — one Mega Lottery draw (PROMPT 8).
 *
 * WHY A NEW TABLE RATHER THAN REUSING AN EXISTING ONE
 * ---------------------------------------------------------------------------
 * Checked before writing this file: `draws` belongs to the operator betting
 * lane and carries markets, cutoffs and bet relations this product has none
 * of; `glo_*` tables model the L6/N3 prize ladder; `national_lottery_draws`
 * models a different product with a different field set (3Up/2Up/3Front/
 * 3After/2Down) and no notion of a draw that publishes nothing at all.
 * Forcing the Mega lane into any of them would mean adding columns that are always
 * null for every other consumer, which is how a shared table becomes a table
 * nobody can reason about.
 *
 * ONE DRAW PER DATE. draw_date is UNIQUE. That constraint, not application
 * code, is what makes two concurrent importers of the same date produce one
 * draw: the loser catches the violation and reads the winner's row.
 *
 * result_status IS PART OF THE DRAW, NOT AN ABSENCE OF DATA
 * ---------------------------------------------------------------------------
 * The reference product this lane models has draws with no published numbers.
 * Representing that as "a draw row with no result row" is ambiguous - it looks
 * identical to "we have not imported this yet". So the draw records its own
 * status explicitly: 'published' means numbers exist, 'unavailable' means the
 * draw happened and the numbers are not available. A page can then say which
 * one it is instead of guessing.
 *
 * current_result_version_id IS DELIBERATELY NOT A FOREIGN KEY. The versions
 * table references this one, so a constraint in the other direction would be a
 * cycle. It is written only by BingoLotteryImportService, inside the same
 * transaction that creates the version it points at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bingo_lottery_draws', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Public route key. A URL must never expose an auto-increment id:
            // it tells a visitor how many rows exist and invites them to walk
            // the table.
            $table->string('draw_reference', 40)->unique();

            // Exactly 'Y-m-d'. Stored as a string by the model's mutator so
            // that every driver - including SQLite, which has no DATE type -
            // holds ten characters and this unique index keeps its meaning.
            $table->date('draw_date')->unique();

            $table->unsignedSmallInteger('draw_year')->index();

            // The business timezone the draw_date is expressed in, recorded so
            // a later reader never has to assume it.
            $table->string('draw_timezone', 64)->default('Asia/Bangkok');

            // 'published' | 'unavailable' — see the class docblock.
            $table->string('result_status', 24)->default('unavailable')->index();

            // App\Enums\DrawPublicationStatus
            $table->string('publication_status', 24)->default('pending')->index();
            $table->timestamp('published_at')->nullable();

            // App\Enums\GloSourceState value of the version currently answering.
            $table->string('source_state', 40)->default('UNAVAILABLE')->index();

            $table->unsignedBigInteger('current_result_version_id')->nullable()->index();

            $table->json('metadata')->nullable();
            $table->timestamps();

            // "this year's draws, newest first" and "what is publicly live"
            // are the only two orderings the public surface uses.
            $table->index(['draw_year', 'draw_date']);
            $table->index(['publication_status', 'draw_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bingo_lottery_draws');
    }
};
