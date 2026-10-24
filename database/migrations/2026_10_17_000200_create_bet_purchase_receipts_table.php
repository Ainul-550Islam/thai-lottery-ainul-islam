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
        // Only the new atomic paid purchase writer may capture receipts. No backfill.
        if (! Schema::hasTable('bet_purchase_receipts')) {
            Schema::create('bet_purchase_receipts', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('purchase_ownership_id')->unique('bpr_purchase_unique')->constrained('bet_purchase_ownerships', indexName: 'bpr_purchase_fk')->restrictOnDelete();
                $t->foreignId('ticket_id')->unique('bpr_ticket_unique')->constrained('tickets', indexName: 'bpr_ticket_fk')->restrictOnDelete();
                $t->foreignId('original_transaction_id')->unique('bpr_transaction_unique')->constrained('financial_transactions', indexName: 'bpr_transaction_fk')->restrictOnDelete();
                $t->string('original_financial_key', 128)->unique('bpr_key_unique');
                $t->string('receipt_hash', 64);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('bet_purchase_receipts')->exists()) {
            throw new RuntimeException('Refusing to erase original paid purchase receipt ownership.');
        }
        Schema::dropIfExists('bet_purchase_receipts');
    }
};
