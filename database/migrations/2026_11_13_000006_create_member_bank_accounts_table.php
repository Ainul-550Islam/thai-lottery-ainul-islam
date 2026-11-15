<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->string('bank_code', 32);
            $table->string('account_name', 160);
            $table->text('account_number');
            $table->string('account_number_hash', 64);
            $table->string('last4', 4);
            $table->string('status', 32)->default('pending_verification');
            $table->boolean('is_default')->default(false);
            $table->boolean('name_mismatch')->default(false);
            $table->text('verification_notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['member_id', 'status'], 'member_bank_accounts_member_status_idx');
            $table->index(['member_id', 'account_number_hash'], 'member_bank_accounts_member_hash_idx');
            $table->index('account_number_hash', 'member_bank_accounts_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_bank_accounts');
    }
};
