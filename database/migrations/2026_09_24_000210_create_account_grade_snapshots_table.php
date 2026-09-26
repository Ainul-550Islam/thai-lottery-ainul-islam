<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable account-grade calculation evidence.
 *
 * One row per calculation: never updated in place. Fingerprint ties the
 * snapshot to (user, period, spend, grade, rule_version) so concurrent
 * recalculations cannot silently fork history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_grade_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->decimal('qualifying_spend', 20, 2);
            $table->string('grade_key', 32);
            $table->string('previous_grade_key', 32)->nullable();
            $table->string('rule_version', 16);
            $table->timestamp('calculated_at')->index();
            $table->string('fingerprint', 64)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'calculated_at']);
            $table->index(['user_id', 'grade_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_grade_snapshots');
    }
};
