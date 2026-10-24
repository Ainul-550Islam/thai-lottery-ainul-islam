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
        // Permanent facts about NEW original credits, never an adoption/backfill.
        if (! Schema::hasTable('agent_commission_payouts')) {
            Schema::create('agent_commission_payouts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('commission_id')->unique()->constrained('agent_commissions')->restrictOnDelete();
                $table->foreignId('source_ownership_id')->unique()->constrained('bet_commission_ownerships')->restrictOnDelete();
                $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
                $table->foreignId('financial_transaction_id')->unique()->constrained('financial_transactions')->restrictOnDelete();
                $table->string('payout_key', 128)->unique();
                $table->string('currency', 3);
                $table->string('amount', 32);
                $table->string('terms_hash', 64);
                $table->string('original_hash', 64);
                $table->string('mode', 16);
                $table->timestamp('paid_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('agent_commission_payouts')->exists()) {
            throw new RuntimeException('Refusing to erase durable original commission payout history.');
        }
        Schema::dropIfExists('agent_commission_payouts');
    }
};
