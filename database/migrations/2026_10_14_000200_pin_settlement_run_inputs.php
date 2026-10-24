<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing unpinned runs are deliberately NOT guessed/backfilled.
        // They require operator reconciliation before any resume can succeed.
        Schema::table('settlement_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('settlement_runs', 'result_fingerprint')) {
                $table->string('result_fingerprint', 64)->nullable();
            }
            if (! Schema::hasColumn('settlement_runs', 'participants_fingerprint')) {
                $table->string('participants_fingerprint', 64)->nullable();
            }
            if (! Schema::hasColumn('settlement_runs', 'max_bet_id')) {
                $table->unsignedBigInteger('max_bet_id')->nullable();
            }
            if (! Schema::hasColumn('settlement_runs', 'approval_threshold')) {
                $table->decimal('approval_threshold', 20, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settlement_runs', function (Blueprint $table): void {
            $table->dropColumn(['result_fingerprint', 'participants_fingerprint', 'max_bet_id', 'approval_threshold']);
        });
    }
};
