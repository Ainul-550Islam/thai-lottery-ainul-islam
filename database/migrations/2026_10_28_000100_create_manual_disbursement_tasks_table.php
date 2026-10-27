<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('manual_disbursement_tasks')) {
            return;
        }

        Schema::create('manual_disbursement_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('withdrawal_id')->constrained('withdrawals')->cascadeOnDelete();
            $table->string('method', 32);
            $table->string('status', 24)->default('pending');
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('proof_reference', 190)->nullable()->unique();
            $table->string('claim_idempotency_key', 128)->nullable()->unique();
            $table->string('completion_idempotency_key', 128)->nullable()->unique();
            $table->timestamp('sla_due_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->unsignedInteger('notification_count')->default(0);
            $table->timestamps();
            $table->unique('withdrawal_id');
            $table->index(['status', 'sla_due_at']);
            $table->index(['status', 'last_notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_disbursement_tasks');
    }
};
