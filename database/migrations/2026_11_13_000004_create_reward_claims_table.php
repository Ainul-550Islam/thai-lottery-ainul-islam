<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('reward_catalog_items')->restrictOnDelete();
            $table->unsignedInteger('points_spent');
            $table->string('status', 24)->default('pending');
            $table->string('idempotency_key', 128);
            $table->foreignId('loyalty_ledger_id')->nullable()->constrained('loyalty_point_ledger')->nullOnDelete();
            $table->foreignId('financial_transaction_id')->nullable()->constrained('financial_transactions')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['member_id', 'item_id', 'idempotency_key'],
                'reward_claims_member_item_idempotency_unique',
            );
            $table->index(['member_id', 'status', 'created_at'], 'reward_claims_member_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_claims');
    }
};
