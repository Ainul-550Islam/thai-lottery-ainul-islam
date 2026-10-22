<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * B6.3 — index the batch-membership lookup on payouts.
 *
 * Batch membership is projected, not relational: a payout names its batch at
 * metadata.batch.batch_key, and PayoutBatch::memberPayouts() finds the members
 * with json_extract(metadata, '$.batch.batch_key') = ?.
 *
 * That predicate cannot use an index. MySQL has to evaluate it for every row
 * the preceding predicates leave behind, and memberPayouts() applies no other
 * filter — so resolving one batch reads the whole payouts table. Payouts are
 * append-only: the table only grows, and the query that reads all of it gets
 * slower every day the platform runs.
 *
 * The fix is a generated column. MySQL can project a JSON path into a real,
 * indexable column, so the lookup becomes an index probe while the JSON stays
 * the single source of truth — the column is derived and cannot drift from it.
 *
 * WHY VIRTUAL, NOT STORED: on MySQL 8.x adding a VIRTUAL generated column is a
 * metadata-only operation, so this migration does not rebuild a money table.
 * A STORED column would copy the value into every row and force a full table
 * rebuild. The value is only ever read through the index, so there is nothing
 * to gain from storing it.
 *
 * WHY IT IS SKIPPED OFF MYSQL: SQLite has no generated columns of this shape,
 * and it is what the test suite runs on. Skipping keeps tests working unchanged
 * — memberPayouts() falls back to the JSON predicate there, which is correct,
 * just unindexed.
 *
 * SAFETY: payouts.metadata is cast to `array` on the model, so it is always
 * valid JSON; json_extract cannot fail on it at read time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->supportsGeneratedColumns()) {
            return;
        }

        if (Schema::hasColumn('payouts', 'batch_key_generated')) {
            return;
        }

        Schema::table('payouts', function (Blueprint $table): void {
            $table->string('batch_key_generated', 64)
                ->nullable()
                ->virtualAs("json_unquote(json_extract(metadata, '$.batch.batch_key'))")
                ->comment('Derived from metadata.batch.batch_key; enables an indexed membership lookup.');
        });

        Schema::table('payouts', function (Blueprint $table): void {
            $table->index('batch_key_generated', 'payouts_batch_key_generated_index');
        });
    }

    public function down(): void
    {
        if (! $this->supportsGeneratedColumns()) {
            return;
        }

        if (! Schema::hasColumn('payouts', 'batch_key_generated')) {
            return;
        }

        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropIndex('payouts_batch_key_generated_index');
        });

        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropColumn('batch_key_generated');
        });
    }

    /**
     * Generated columns of this shape exist on MySQL and MariaDB only.
     */
    private function supportsGeneratedColumns(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
