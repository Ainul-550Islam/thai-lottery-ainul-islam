<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('truemoney_voucher_redemptions')) {
            return;
        }

        Schema::create('truemoney_voucher_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('voucher_hash', 64)->unique();
            $table->string('voucher_last4', 4);
            $table->decimal('amount', 20, 2)->nullable();
            $table->string('currency', 3)->default('THB');
            $table->string('status', 32)->default('pending');
            $table->foreignId('deposit_id')->nullable()->constrained('deposits')->nullOnDelete();
            $table->string('provider_status', 64)->nullable();
            $table->json('provider_payload')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'created_at'], 'truemoney_user_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('truemoney_voucher_redemptions');
    }
};
