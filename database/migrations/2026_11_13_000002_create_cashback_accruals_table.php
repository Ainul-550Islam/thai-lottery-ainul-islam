<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashback_accruals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained('cashback_policies')->restrictOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->decimal('basis_amount', 18, 2);
            $table->decimal('accrual_amount', 18, 2);
            $table->string('status', 24)->default('accrued');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('claim_transaction_id')->nullable()->constrained('financial_transactions')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 191)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['member_id', 'policy_id', 'period_start', 'period_end'],
                'cashback_accruals_member_policy_period_unique',
            );
            $table->index(['member_id', 'status', 'expires_at'], 'cashback_accruals_member_claimable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashback_accruals');
    }
};
