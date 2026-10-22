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
            $table->string('result_fingerprint', 64)->nullable();
            $table->string('participants_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('max_bet_id')->nullable();
            $table->decimal('approval_threshold', 20, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settlement_runs', function (Blueprint $table): void {
            $table->dropColumn(['result_fingerprint', 'participants_fingerprint', 'max_bet_id', 'approval_threshold']);
        });
    }
};
