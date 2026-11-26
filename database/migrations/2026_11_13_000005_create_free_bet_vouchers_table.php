<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('free_bet_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('claim_id')->nullable()->unique()->constrained('reward_claims')->nullOnDelete();
            $table->string('code', 64)->unique();
            $table->string('currency', 3)->default('THB');
            $table->decimal('amount', 18, 2);
            $table->string('status', 24)->default('active');
            $table->timestamp('expires_at');
            $table->foreignId('consumed_bet_id')->nullable()->constrained('bets')->nullOnDelete();
            $table->timestamp('consumed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status', 'expires_at'], 'free_bet_vouchers_member_usable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('free_bet_vouchers');
    }
};
