<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('manual_wallet_deposit_proofs')) {
            return;
        }

        Schema::create('manual_wallet_deposit_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deposit_id')->constrained('deposits')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('method', 32);
            $table->string('sender_number', 16);
            $table->string('trx_id', 32);
            $table->timestamp('submitted_at');
            $table->timestamps();

            // One proof per deposit, and one TrxID per method: a single real
            // payment can never back two deposits.
            $table->unique('deposit_id', 'manual_wallet_proofs_deposit_unique');
            $table->unique(['method', 'trx_id'], 'manual_wallet_proofs_method_trx_unique');
            $table->index(['user_id', 'submitted_at'], 'manual_wallet_proofs_user_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_wallet_deposit_proofs');
    }
};
