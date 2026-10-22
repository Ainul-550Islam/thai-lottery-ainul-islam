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
        // NULL is historical/unapproved; never infer a contract from old credits.
        Schema::table('agent_commission_payouts', fn (Blueprint $table) => $table->string('cancellation_policy', 128)->nullable());
        Schema::create('agent_commission_compensations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payout_id')->unique('acc_payout_unique')->constrained('agent_commission_payouts', indexName: 'acc_payout_fk')->restrictOnDelete();
            $table->foreignId('ownership_id')->unique('acc_ownership_unique')->constrained('bet_commission_ownerships', indexName: 'acc_ownership_fk')->restrictOnDelete();
            $table->foreignId('original_transaction_id')->unique('acc_original_unique')->constrained('financial_transactions', indexName: 'acc_original_fk')->restrictOnDelete();
            $table->foreignId('compensation_transaction_id')->unique('acc_compensation_unique')->constrained('financial_transactions', indexName: 'acc_compensation_fk')->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets', indexName: 'acc_wallet_fk')->restrictOnDelete();
            $table->string('amount', 32);
            $table->string('currency', 3);
            $table->string('policy_reference', 128);
            $table->string('payout_fact_hash', 64);
            $table->string('compensation_hash', 64);
            $table->string('cancelled_reason', 64);
            $table->timestamp('cancelled_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('agent_commission_compensations')->exists()
            || DB::table('agent_commission_payouts')->whereNotNull('cancellation_policy')->exists()) {
            throw new RuntimeException('Refusing to erase original-specific compensation or captured cancellation policy history.');
        }
        Schema::dropIfExists('agent_commission_compensations');
        Schema::table('agent_commission_payouts', fn (Blueprint $table) => $table->dropColumn('cancellation_policy'));
    }
};
