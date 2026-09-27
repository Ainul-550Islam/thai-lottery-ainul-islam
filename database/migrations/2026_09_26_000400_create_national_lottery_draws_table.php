<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * national_lottery_draws — the canonical identity of one National Lottery draw.
 *
 * WHY A NEW TABLE RATHER THAN REUSING `draws`
 * ---------------------------------------------------------------------------
 * `draws` is the operator betting lane: it carries type (2d/3d/run), open/close
 * windows, total_bets, total_amount_wagered, house_profit and a settlement
 * lifecycle. A National Lottery draw has none of those - it has a date, a
 * published result and a provenance trail. Forcing it into `draws` would mean
 * either nullable betting columns that mean nothing, or a DrawType case that
 * makes the betting engine believe a market exists. Both are worse than a
 * small dedicated table, and the brief is explicit that this is a separate
 * product lane until an authoritative source proves otherwise.
 *
 * `glo_*` is likewise not reused: those tables model the GLO prize ladder,
 * tickets, claims and freezes. This lane stores no ticket and pays nobody.
 *
 * ONE DRAW PER DATE
 * draw_date carries a UNIQUE index. That constraint is what makes a duplicate
 * import a no-op at the database level rather than a race that has to be won
 * in PHP.
 *
 * current_result_version_id HAS NO FOREIGN KEY, DELIBERATELY
 * The three tables in this lane reference each other in a cycle (draw →
 * version → result → draw). A real FK here would force a circular constraint
 * that SQLite cannot add after the fact and that buys nothing: the column is
 * written only by NationalLotteryImportService inside a transaction that has
 * just created the version row. It is indexed so the join stays cheap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('national_lottery_draws', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Public, stable identity used in URLs. Never a database id.
            $table->string('draw_reference', 40)->unique();

            // The business date of the draw, in config('national_lottery.timezone').
            // UNIQUE: one National Lottery draw per date.
            $table->date('draw_date')->unique();

            // Gregorian year, denormalised so a year page is an index seek and
            // never a function call on a column (which would defeat the index).
            $table->unsignedSmallInteger('draw_year')->index();

            // Lane lifecycle, distinct from publication.
            $table->string('status', 24)->default('awaiting_result')->index();

            // App\Enums\DrawPublicationStatus — REUSED, not duplicated.
            $table->string('publication_status', 24)->default('pending')->index();
            $table->timestamp('published_at')->nullable();

            // App\Enums\GloSourceState value. The enum is the platform's shared
            // provenance vocabulary; the lane it describes is still separate.
            $table->string('source_state', 40)->default('UNAVAILABLE')->index();

            // See the class docblock: intentionally not a foreign key.
            $table->unsignedBigInteger('current_result_version_id')->nullable()->index();

            $table->json('metadata')->nullable();

            $table->timestamps();

            // Year page: WHERE draw_year = ? ORDER BY draw_date DESC.
            $table->index(['draw_year', 'draw_date']);

            // Landing page: WHERE publication_status = 'published' ORDER BY draw_date DESC.
            $table->index(['publication_status', 'draw_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('national_lottery_draws');
    }
};
