<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * national_lottery_results — the numbers themselves, one row per version.
 *
 * FIXED-WIDTH STRINGS, NOT NUMBERS
 * ---------------------------------------------------------------------------
 * first_prize is CHAR(6), three_up is CHAR(3), two_up and two_down are CHAR(2).
 * An INTEGER column would silently destroy '004615' the moment it was written
 * and there would be no way to recover the two leading zeros afterwards,
 * because 4615 and 004615 are the same integer. The width is part of the data.
 *
 * Nothing in this lane ever compares these columns with a numeric operator, so
 * the string type costs nothing at query time: the search path is an equality
 * probe on an index.
 *
 * 3FRONT / 3AFTER ARE LISTS, AND THEIR LENGTH COMES FROM THE SOURCE
 * ---------------------------------------------------------------------------
 * They are stored as JSON arrays of three-character strings, each with an
 * explicit count column, rather than as one opaque '060-521-266-041' blob.
 * Two reasons:
 *
 *   1. a delimited blob cannot be queried for a single value without a LIKE
 *      scan, and a LIKE scan on a growing history table is the performance
 *      bug this schema exists to avoid;
 *   2. splitting a blob later requires guessing which half is 3Front and which
 *      is 3After. Guessing is exactly what the brief forbids.
 *
 * The counts are stored because the number of values is a property of the
 * SOURCE, not a constant this application is entitled to assume. A source that
 * publishes two 3Front values and a source that publishes one are both
 * representable, and the page renders what was actually received.
 *
 * ORDER IS PRESERVED, NOT IMPOSED. The JSON array keeps the source's order.
 * No sort is applied anywhere, because a sort would assert a ranking the
 * source never stated.
 *
 * ONE ROW PER VERSION, NOT ONE ROW PER DRAW
 * A correction does not UPDATE this table. It inserts a new row against a new
 * version and flips is_current. The superseded row stays readable forever,
 * which is what makes the audit trail meaningful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('national_lottery_results', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('draw_id')
                ->constrained('national_lottery_draws')
                ->cascadeOnDelete();

            // NOT a foreign key, and not an oversight. The brief fixes the
            // migration order as draws → results → result_versions, so the
            // versions table does not exist yet when this table is created,
            // and SQLite (the test connection) cannot add a foreign key to an
            // existing table afterwards. The column is indexed and is written
            // only by NationalLotteryImportService, inside the same
            // transaction that creates the version row it points at.
            $table->unsignedBigInteger('result_version_id')->index();

            // --- Result fields: fixed-width strings, leading zeros intact ---
            $table->char('first_prize', 6);
            $table->char('three_up', 3)->nullable();
            $table->char('two_up', 2)->nullable();
            $table->char('two_down', 2)->nullable();

            // Lists, plus the cardinality the source actually delivered.
            $table->json('three_front')->nullable();
            $table->json('three_after')->nullable();
            $table->unsignedTinyInteger('three_front_count')->default(0);
            $table->unsignedTinyInteger('three_after_count')->default(0);

            // Exactly one current row per draw is enforced in the service
            // transaction; the partial-unique form is not portable to SQLite,
            // so the composite index below keeps the lookup cheap instead.
            $table->boolean('is_current')->default(false);

            $table->timestamps();

            // A version contributes at most one result row.
            $table->unique(['draw_id', 'result_version_id']);

            // Six-digit search: exact string equality against an index.
            $table->index('first_prize');

            // Secondary category searches stay index-backed too.
            $table->index('three_up');
            $table->index('two_up');
            $table->index('two_down');

            // "the current numbers for this draw" — the hot read path.
            $table->index(['draw_id', 'is_current']);

            // "which draws had this first prize, newest first"
            $table->index(['first_prize', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('national_lottery_results');
    }
};
