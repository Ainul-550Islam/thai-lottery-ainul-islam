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
        if (! Schema::hasTable('withdrawal_reservations')) {
            Schema::create('withdrawal_reservations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('withdrawal_id')->unique()->constrained()->restrictOnDelete();
                $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->string('currency', 3);
                $table->decimal('amount', 20, 2);
                $table->char('terms_hash', 64);
                $table->enum('state', ['active', 'consumed', 'released'])->default('active');
                $table->foreignId('financial_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
                $table->timestamp('created_at');
                $table->timestamp('closed_at')->nullable();
                $table->index(['wallet_id', 'state']);
            });
        }
        // No expiry, inferred historical owner, backfill or wallet adjustment.
    }

    public function down(): void
    {
        if (Schema::hasTable('withdrawal_reservations') && DB::table('withdrawal_reservations')->exists()) {
            throw new RuntimeException('Preserve non-expiring withdrawal reservation ownership before rollback.');
        }
        Schema::dropIfExists('withdrawal_reservations');
    }
};
