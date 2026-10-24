<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payout_batch_members')) {
            Schema::create('payout_batch_members', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('payout_batch_id')->constrained()->restrictOnDelete();
                $table->foreignId('payout_id')->unique()->constrained()->restrictOnDelete();
                $table->char('facts_hash', 64);
                $table->string('method', 32);
                $table->string('currency', 3);
                $table->decimal('amount', 20, 2);
                $table->timestamp('created_at');
            });
        }
        // No guessed legacy membership, reassignment or counter backfill.
    }

    public function down(): void
    {
        if (Schema::hasTable('payout_batch_members') && DB::table('payout_batch_members')->exists()) {
            throw new RuntimeException('Refused: preserve immutable payout batch membership before rollback.');
        }
        Schema::dropIfExists('payout_batch_members');
    }
};
