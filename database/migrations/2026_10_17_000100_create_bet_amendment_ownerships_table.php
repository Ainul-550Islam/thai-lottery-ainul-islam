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
        // No historical enrollment, state defaults, expiry or ownership backfill.
        Schema::create('bet_amendment_ownerships', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('amendment_id')->unique('bao_amendment_unique')->constrained('bet_amendments', indexName: 'bao_amendment_fk')->restrictOnDelete();
            $t->foreignId('purchase_ownership_id')->unique('bao_purchase_unique')->constrained('bet_purchase_ownerships', indexName: 'bao_purchase_fk')->restrictOnDelete();
            $t->foreignId('wallet_id')->constrained('wallets', indexName: 'bao_wallet_fk')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users', indexName: 'bao_user_fk')->restrictOnDelete();
            $t->foreignId('draw_id')->constrained('draws', indexName: 'bao_draw_fk')->restrictOnDelete();
            $t->string('currency', 3);
            $t->string('market', 32);
            $t->string('old_number', 16);
            $t->string('old_stake', 32);
            $t->string('new_number', 16);
            $t->string('new_stake', 32);
            $t->string('source_hash', 64);
            $t->string('request_hash', 64);
            $t->string('terms_hash', 64);
            $t->string('replacement_client_key', 128)->unique('bao_client_unique');
            $t->string('replacement_bet_key', 128)->unique('bao_bet_key_unique');
            $t->string('replacement_financial_key', 128)->unique('bao_fin_key_unique');
            $t->foreignId('refund_transaction_id')->unique('bao_refund_unique')->constrained('financial_transactions', indexName: 'bao_refund_fk')->restrictOnDelete();
            $t->string('state', 32);
            $t->foreignId('replacement_ownership_id')->nullable()->unique('bao_replacement_unique')->constrained('bet_purchase_ownerships', indexName: 'bao_replacement_fk')->restrictOnDelete();
            $t->string('replacement_hash', 64)->nullable();
            $t->string('failure_code', 128)->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('bet_amendment_ownerships')->exists()) {
            throw new RuntimeException('Refusing to erase non-expiring amendment ownership, attempted keys or original refund/replacement facts.');
        }
        Schema::dropIfExists('bet_amendment_ownerships');
    }
};
