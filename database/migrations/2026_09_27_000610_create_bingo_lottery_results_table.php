<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bingo_lottery_results — the numbers of one result version (PROMPT 8).
 *
 * CHAR, NOT INTEGER, AND THE REASON IS NOT STYLE
 * ---------------------------------------------------------------------------
 * An INTEGER column destroys '001234' the moment it is written, and no
 * application code downstream can recover it, because 1234 and 001234 are the
 * same integer. The width is part of the data: a 3 Mega of '049' is not the
 * number 49, it is three characters that happen to be digits. CHAR(6) /
 * CHAR(3) / CHAR(2) make the wrong value unstorable rather than merely
 * discouraged.
 *
 * NULLABLE, AND THAT IS THE POINT
 * ---------------------------------------------------------------------------
 * A draw can exist with no published numbers. The honest representation is
 * NULL in all three columns with the draw marked unavailable - never 000000 /
 * 000 / 00, which are three fabricated results in the shape of real ones. A
 * visitor reading 000000 has no way to know it was invented.
 *
 * ONE ROW PER VERSION
 * ---------------------------------------------------------------------------
 * A correction inserts a NEW row and flips the old row's is_current to false.
 * No result value is ever UPDATEd, which is what lets the detail page show a
 * correction honestly instead of silently rewriting history.
 *
 * result_version_id is an indexed column and NOT a foreign key: the brief
 * fixes the migration order as draws -> results -> result_versions, so the
 * versions table does not exist yet, and SQLite cannot add a foreign key to an
 * existing table afterwards. It is written only by BingoLotteryImportService,
 * in the same transaction that creates the version row it points at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bingo_lottery_results', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('draw_id')
                ->constrained('bingo_lottery_draws')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('result_version_id')->index();

            // The three public values. Strings, fixed width, nullable.
            $table->char('first_6_mega', 6)->nullable();
            $table->char('three_mega', 3)->nullable();
            $table->char('two_mega', 2)->nullable();

            // 'published' | 'unavailable'. Denormalised from the draw so a
            // result row is self-describing: a reader of this table alone can
            // tell "no numbers" from "numbers not imported".
            $table->string('result_status', 24)->default('published')->index();

            // Exactly one row per draw may answer the public surface.
            $table->boolean('is_current')->default(false);

            $table->timestamps();

            // A version states its numbers once.
            $table->unique(['draw_id', 'result_version_id']);

            // Public search reads these directly. String columns, string
            // comparisons, index-backed.
            $table->index('first_6_mega');
            $table->index('three_mega');
            $table->index('two_mega');
            $table->index(['draw_id', 'is_current']);
            $table->index(['first_6_mega', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bingo_lottery_results');
    }
};
