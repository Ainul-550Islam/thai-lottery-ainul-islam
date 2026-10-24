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
        // Only the atomic purchase writer may establish ownership. Never infer
        // historical funding or risk ownership from a balance, status or reference.
        if (! Schema::hasTable('bet_purchase_ownerships')) {
            Schema::create('bet_purchase_ownerships', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('bet_id')->unique()->constrained('bets')->restrictOnDelete();
                $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
                $table->foreignId('number_limit_id')->constrained('number_limits')->restrictOnDelete();
                $table->foreignId('original_transaction_id')->unique()->constrained('financial_transactions')->restrictOnDelete();
                $table->foreignId('refund_transaction_id')->nullable()->unique()->constrained('financial_transactions')->restrictOnDelete();
                $table->string('currency', 3);
                // Canonical decimal STRINGS also retain exact values on SQLite.
                $table->string('stake_amount', 32);
                $table->string('potential_payout', 32);
                $table->string('terms_hash', 64);
                $table->string('original_hash', 64);
                $table->string('refund_hash', 64)->nullable();
                $table->string('state', 16)->default('active');
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancelled_reason', 64)->nullable();
                $table->timestamps();
                $table->index(['number_limit_id', 'state'], 'bet_ownership_limit_state_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bet_purchase_ownerships') && DB::table('bet_purchase_ownerships')->exists()) {
            throw new RuntimeException('Refusing to erase durable bet payment/refund ownership history. Controlled retention review is required.');
        }
        Schema::dropIfExists('bet_purchase_ownerships');
    }
};
