<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROMPT 2 — extend account_grade_snapshots for the grade parity batch.
 *
 * Adds the three evaluation-provenance columns the canonical evaluator
 * records on every snapshot:
 *   grade_discount_rate  decimal(12,4)  the tier fraction applied
 *   entitlement_hash     nullable string stable (level|games|rv) hash
 *   source_version       string          evaluator algorithm version
 *
 * APPEND-ONLY, NON-DESTRUCTIVE:
 *   - every column is added only when missing (add-if-missing guards),
 *     so the migration is safe to re-run on a partially-migrated
 *     environment;
 *   - existing rows keep their historical meaning: the defaults fill
 *     the new columns with the values the OLD algorithm implies
 *     (rate 0.0000 = "grade discounts were not recorded", source
 *     version '1' = the pre-batch evaluator), they never invent
 *     numbers;
 *   - the migration is fully reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_grade_snapshots', function (Blueprint $table): void {
            if (! Schema::hasColumn('account_grade_snapshots', 'grade_discount_rate')) {
                $table->decimal('grade_discount_rate', 12, 4)
                    ->default('0.0000')
                    ->after('grade_key');
            }

            if (! Schema::hasColumn('account_grade_snapshots', 'entitlement_hash')) {
                $table->string('entitlement_hash')
                    ->nullable()
                    ->after('grade_discount_rate');
            }

            if (! Schema::hasColumn('account_grade_snapshots', 'source_version')) {
                $table->string('source_version')
                    ->default('1')
                    ->after('rule_version');
            }

            if (! Schema::hasIndex('account_grade_snapshots', 'account_grade_snapshots_rule_version_index')) {
                $table->index('rule_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('account_grade_snapshots', function (Blueprint $table): void {
            if (Schema::hasIndex('account_grade_snapshots', 'account_grade_snapshots_rule_version_index')) {
                $table->dropIndex('account_grade_snapshots_rule_version_index');
            }

            foreach (['source_version', 'entitlement_hash', 'grade_discount_rate'] as $column) {
                if (Schema::hasColumn('account_grade_snapshots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
