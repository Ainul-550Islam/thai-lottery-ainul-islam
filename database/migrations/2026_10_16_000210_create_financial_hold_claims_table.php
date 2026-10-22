<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Local ownership only. Historical aggregates are deliberately not backfilled. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_hold_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('financial_hold_id')->unique()->constrained('financial_holds')->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 20, 2);
            $table->string('currency', 3);
            $table->string('source_reference', 64);
            $table->unique(['wallet_id', 'source_reference']);
            $table->char('terms_hash', 64);
            $table->char('input_hash', 64);
            $table->string('state', 16);
            $table->foreignId('financial_transaction_id')->nullable()->unique()->constrained('financial_transactions')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['wallet_id', 'state', 'id']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('financial_hold_claims') && DB::table('financial_hold_claims')->exists()) {
            throw new LogicException('Durable financial-hold ownership cannot be dropped by routine rollback. Controlled archival/migration authorization is required.');
        }
        Schema::dropIfExists('financial_hold_claims');
    }
};
