<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('thai_bank_payout_attempts')) {
            return;
        }

        Schema::create('thai_bank_payout_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('withdrawal_id')->constrained('withdrawals')->restrictOnDelete();
            $table->string('withdrawal_reference', 64)->unique();
            $table->string('bank', 16);
            $table->decimal('amount', 20, 2);
            $table->string('destination_type', 32);
            $table->string('destination_masked', 32);
            $table->string('state', 24)->default('prepared');
            $table->string('provider_reference', 191)->nullable()->unique();
            $table->string('request_fingerprint', 64);
            $table->boolean('outcome_unknown')->default(false);
            $table->timestamp('last_polled_at')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();
            $table->index(['state', 'last_polled_at'], 'thai_payout_state_polled_idx');
            $table->index(['bank', 'created_at'], 'thai_payout_bank_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thai_bank_payout_attempts');
    }
};
