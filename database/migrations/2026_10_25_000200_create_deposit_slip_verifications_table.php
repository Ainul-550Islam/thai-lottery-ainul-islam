<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deposit_slip_verifications')) {
            return;
        }

        Schema::create('deposit_slip_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('deposit_id')->nullable()->constrained('deposits')->nullOnDelete();
            $table->string('trans_ref', 191)->nullable()->unique();
            $table->string('sending_bank', 80)->nullable();
            $table->string('receiving_account_masked', 80)->nullable();
            $table->decimal('amount', 20, 2)->nullable();
            $table->string('currency', 3)->default('THB');
            $table->timestamp('slip_datetime')->nullable();
            $table->string('provider', 32);
            $table->string('status', 32)->default('pending');
            $table->string('review_reason', 120)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            // Match the referenced UUID column on every supported grammar.
            // MariaDB's native UUID type is not FK-compatible with VARCHAR(36).
            $table->uuid('upload_scan_record_id')->nullable();
            $table->foreign('upload_scan_record_id', 'slip_verify_scan_record_fk')
                ->references('id')->on('upload_scan_records')->nullOnDelete();
            $table->json('provider_payload')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status'], 'slip_verify_user_status_idx');
            $table->index(['status', 'created_at'], 'slip_verify_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_slip_verifications');
    }
};
