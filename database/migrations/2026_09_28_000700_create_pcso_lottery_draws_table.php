<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pcso_lottery_draws — one PCSO Lottery draw (PROMPT 9).
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
 * cycle. It is written only by PcsoLotteryImportService, inside the same
 * transaction that creates the version it points at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcso_lottery_draws', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Public route key. A URL must never expose an auto-increment id:
            // it tells a visitor how many rows exist and invites them to walk
            // the table.
            $table->string('draw_reference', 40)->unique();

            // Exactly 'Y-m-d'. Stored as a string by the model's mutator so
            // that every driver - including SQLite, which has no DATE type -
            // holds ten characters.
            //
            // NOT UNIQUE HERE, unlike every other lane. PCSO publishes several
            // draws on one calendar date, so a unique index on draw_date would
            // reject the 17:00 result as a duplicate of the 14:00 one. The
            // composite index below is what enforces identity instead.
            $table->date('draw_date')->index();

            // Canonical local clock reading, 'HH:MM', 24-hour.
            //
            // A STRING, not a time or a datetime. It is the time printed on
            // the draw in Asia/Bangkok, not an instant: stored as a datetime
            // it would be converted by a driver or a server in another zone
            // and a 21:00 draw would surface as 14:00 the following day.
            $table->string('draw_time_local', 5);

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
            // IDENTITY. One draw per date-and-time. This is what makes the
            // concurrent import case safe: the loser of a race hits this
            // constraint and re-reads the winner rather than writing a second
            // row for the same draw.
            $table->unique(['draw_date', 'draw_time_local']);

            // "this year's draws, newest first" and "what is publicly live"
            // are the orderings the public surface uses. Both carry the time,
            // because within a date the time is what orders the draws.
            $table->index(['draw_year', 'draw_date', 'draw_time_local']);
            $table->index(['publication_status', 'draw_date', 'draw_time_local'], 'pcso_draws_status_date_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pcso_lottery_draws');
    }
};
