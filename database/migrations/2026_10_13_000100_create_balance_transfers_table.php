<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('balance_transfers')) {
            return;
        }

        Schema::create('balance_transfers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference_number', 32)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('source_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->foreignId('destination_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->string('direction', 32);
            $table->string('currency', 8)->default('THB');
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('fee_amount', 18, 2)->default(0);
            $table->decimal('total_debited', 18, 2)->default(0);
            $table->string('fee_category', 64)->nullable();
            $table->decimal('fee_rate', 10, 6)->default(0);
            $table->decimal('source_balance_before', 18, 2)->nullable();
            $table->decimal('source_balance_after', 18, 2)->nullable();
            $table->decimal('destination_balance_before', 18, 2)->nullable();
            $table->decimal('destination_balance_after', 18, 2)->nullable();
            $table->unsignedBigInteger('debit_transaction_id')->nullable();
            $table->unsignedBigInteger('credit_transaction_id')->nullable();
            $table->unsignedBigInteger('fee_transaction_id')->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('idempotency_key', 128)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->string('request_ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key'], 'balance_transfers_user_idempotency_unique');
            $table->index(['user_id', 'direction', 'status', 'completed_at'], 'balance_transfers_user_direction_status_completed_idx');
            $table->index(['user_id', 'created_at'], 'balance_transfers_user_created_idx');
            $table->index(['status', 'created_at'], 'balance_transfers_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_transfers');
    }
};
